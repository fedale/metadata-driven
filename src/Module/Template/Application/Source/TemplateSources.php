<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Source;

use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use App\Module\Template\Domain\Model\TemplateSource;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
use Fedale\TemplateFormat\Archive;
use Fedale\TemplateFormat\Format;
use Fedale\TemplateFormat\InvalidIndex;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\Signature;
use Fedale\TemplateFormat\TemplateIndex;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The indexes this instance looks into, and the templates they offer: the template catalog when
 * configured (TEMPLATE_CATALOG_URL), then the indexes added with metadata:template:repo.
 *
 * Everything comes over https, within size limits; an archive is checked against the checksum
 * its index lists before anything is unpacked, and unpacked by Archive, which keeps every file
 * inside the target.
 *
 * An index with a public key (the catalog's always has one) vouches for its entries with
 * signatures: an entry without a valid one is refused. An index without a key is used only once
 * the user accepted it unsigned (repo add --unsigned): its checksums prove an archive is the one
 * it lists, not who wrote it.
 */
final class TemplateSources
{
    public const string CATALOG = 'catalog';
    private const string NAME_PATTERN = '~^[a-z][a-z0-9-]{0,49}$~';
    // An index is a list: a few MB is already a very large one.
    private const int MAX_INDEX_BYTES = 5_000_000;

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly TemplateSourceRepository $sources,
        HttpClientInterface $http,
        private readonly Archive $archive,
        #[Autowire('%env(default::TEMPLATE_CATALOG_URL)%')]
        private readonly ?string $catalogUrl,
        #[Autowire('%env(default::TEMPLATE_CATALOG_KEY)%')]
        private readonly ?string $catalogKey,
        #[Autowire(param: 'app.template.max_download_mb')]
        private readonly int $maxDownloadMb,
        #[Autowire(param: 'app.template.max_unpacked_mb')]
        private readonly int $maxUnpackedMb,
        #[Autowire(param: 'app.template.allow_private_networks')]
        bool $allowPrivateNetworks,
    ) {
        // An index names the addresses of its archives, and may redirect: without this, a hostile
        // index could make the server request its own network (a metadata service, an admin page).
        $this->http = $allowPrivateNetworks ? $http : new NoPrivateNetworkHttpClient($http);
    }

    /**
     * @return list<array{name: string, url: string, key: string|null}> the catalog first
     *
     * @throws TemplateSourceFailed the catalog is configured without its key
     */
    public function indexes(): array
    {
        $indexes = [];
        if (null !== $this->catalogUrl && '' !== $this->catalogUrl) {
            // The catalog is signed, always: an unsigned catalog would be anybody's.
            if (null === $this->catalogKey || !Signature::isPublicKey($this->catalogKey)) {
                throw new TemplateSourceFailed('TEMPLATE_CATALOG_URL is set without TEMPLATE_CATALOG_KEY, the public key the catalog signs with.');
            }
            $indexes[] = ['name' => self::CATALOG, 'url' => $this->catalogUrl, 'key' => $this->catalogKey];
        }
        foreach ($this->sources->all() as $source) {
            $indexes[] = ['name' => $source->getName(), 'url' => $source->getUrl(), 'key' => $source->getPublicKey()];
        }

        return $indexes;
    }

    /**
     * Adds an index, once it has been read and found valid, and, with a key, every entry found
     * signed with it.
     *
     * @param string|null $publicKey the key its entries are signed with
     * @param bool        $unsigned  without a key: the user accepts it unsigned
     *
     * @throws TemplateSourceFailed
     */
    public function add(string $name, string $url, ?string $publicKey = null, bool $unsigned = false): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name) || self::CATALOG === $name) {
            throw new TemplateSourceFailed(\sprintf('"%s" cannot name an index: lowercase letters, digits and hyphens, and not "%s".', $name, self::CATALOG));
        }
        if (null !== $this->sources->findByName($name)) {
            throw new TemplateSourceFailed(\sprintf('An index is already named "%s".', $name));
        }
        if (null === $publicKey && !$unsigned) {
            throw new TemplateSourceFailed('Give the public key its entries are signed with (--key), or accept it unsigned (--unsigned), trusting its checksums only.');
        }
        if (null !== $publicKey && !Signature::isPublicKey($publicKey)) {
            throw new TemplateSourceFailed('This is not an Ed25519 public key in base64.');
        }
        $index = $this->index($url);
        if (null !== $publicKey) {
            foreach ($index->names() as $template) {
                foreach ($index->versions($template) as $entry) {
                    if (!Signature::verify($entry, $publicKey)) {
                        throw new TemplateSourceFailed(\sprintf('%s %s in this index is not signed with this key.', $entry->name, $entry->version));
                    }
                }
            }
        }
        $this->sources->save(new TemplateSource(Uuid::v7(), $name, $url, $publicKey, new \DateTimeImmutable()));
    }

    /**
     * @throws TemplateSourceFailed
     */
    public function remove(string $name): void
    {
        $this->sources->remove($this->sources->findByName($name) ?? throw new TemplateSourceFailed(\sprintf('No index is named "%s".', $name)));
    }

    /**
     * @throws TemplateSourceFailed
     */
    public function index(string $url): TemplateIndex
    {
        try {
            return TemplateIndex::fromJson($this->fetch($url, self::MAX_INDEX_BYTES), $url);
        } catch (InvalidIndex $e) {
            throw new TemplateSourceFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * The newest version of every template whose name holds the term, in every index.
     *
     * @return list<LocatedTemplate>
     *
     * @throws TemplateSourceFailed
     */
    public function search(string $term = ''): array
    {
        $found = [];
        foreach ($this->indexes() as $source) {
            $index = $this->index($source['url']);
            foreach ($index->names() as $name) {
                $latest = $index->latest($name);
                if (null !== $latest && ('' === $term || str_contains($name, strtolower($term)))) {
                    $found[] = new LocatedTemplate($source['name'], $source['url'], $latest, $source['key']);
                }
            }
        }

        return $found;
    }

    /**
     * A version of a template, in the one index offering it.
     *
     * @param string|null $version null for the newest one, yanked versions left out
     * @param string|null $source  the name of the index to look in; null for all of them
     * @param string|null $prefer  the address of an index to look in first (where it was installed from)
     *
     * @throws TemplateSourceFailed
     */
    public function locate(string $name, ?string $version = null, ?string $source = null, ?string $prefer = null): LocatedTemplate
    {
        $indexes = $this->indexes();
        if (null !== $source) {
            $indexes = array_values(array_filter($indexes, static fn (array $index): bool => $index['name'] === $source));
            if ([] === $indexes) {
                throw new TemplateSourceFailed(\sprintf('No index is named "%s" (metadata:template:repo list).', $source));
            }
        } else {
            $preferred = array_values(array_filter($indexes, static fn (array $index): bool => $index['url'] === $prefer));
            $indexes = [] === $preferred ? $indexes : $preferred;
        }

        $found = [];
        foreach ($indexes as $index) {
            $read = $this->index($index['url']);
            if ([] !== $read->versions($name)) {
                $found[] = [$index, $read];
            }
        }
        if ([] === $found) {
            throw new TemplateSourceFailed(\sprintf('No index offers %s.', $name));
        }
        if (\count($found) > 1) {
            throw new TemplateSourceFailed(\sprintf('%s is offered by %s: choose one with --repo.', $name, implode(' and ', array_map(static fn (array $pair): string => $pair[0]['name'], $found))));
        }

        [$index, $read] = $found[0];
        $entry = null === $version ? $read->latest($name) : $read->find($name, $version);
        if (null === $entry) {
            throw new TemplateSourceFailed(null === $version ? \sprintf('%s offers no version of %s for format %s that is not yanked.', $index['name'], $name, Format::VERSION) : \sprintf('%s offers no version %s of %s.', $index['name'], $version, $name));
        }

        return new LocatedTemplate($index['name'], $index['url'], $entry, $index['key']);
    }

    /**
     * Downloads a version's archive, checks it, and unpacks it into a new temporary directory,
     * for the caller to read, then discard().
     *
     * @throws TemplateSourceFailed
     */
    public function download(LocatedTemplate $located): string
    {
        $entry = $located->entry;
        if (null !== $located->publicKey && !Signature::verify($entry, $located->publicKey)) {
            throw new TemplateSourceFailed(\sprintf('%s %s is not signed with the key of %s: it was not downloaded.', $entry->name, $entry->version, $located->source));
        }
        if (Format::VERSION !== ($entry->requires['format'] ?? null)) {
            throw new TemplateSourceFailed(\sprintf('%s %s is of format %s; this instance reads %s.', $entry->name, $entry->version, $entry->requires['format'] ?? '?', Format::VERSION));
        }
        $content = $this->fetch($entry->url, $this->maxDownloadMb * 1_000_000);
        if (!hash_equals($entry->sha256, hash('sha256', $content))) {
            throw new TemplateSourceFailed(\sprintf('The archive of %s %s is not the one its index lists: its checksum differs. Nothing was unpacked.', $entry->name, $entry->version));
        }

        $directory = sys_get_temp_dir().'/md-template-'.bin2hex(random_bytes(8));
        $archive = $directory.'.tar.gz';
        file_put_contents($archive, $content);
        try {
            $this->archive->unpack($archive, $directory, $this->maxUnpackedMb * 1_000_000);
        } catch (InvalidPackage $e) {
            $this->discard($directory);

            throw new TemplateSourceFailed(\sprintf('%s %s: %s', $entry->name, $entry->version, $e->getMessage()), 0, $e);
        } finally {
            (new Filesystem())->remove($archive);
        }

        return $directory;
    }

    public function discard(string $directory): void
    {
        (new Filesystem())->remove($directory);
    }

    /**
     * @throws TemplateSourceFailed
     */
    private function fetch(string $url, int $maxBytes): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new TemplateSourceFailed(\sprintf('%s is not an https address: templates are fetched over https only.', $url));
        }
        try {
            $response = $this->http->request('GET', $url, ['max_redirects' => 5, 'timeout' => 30]);
            if (200 !== $response->getStatusCode()) {
                throw new TemplateSourceFailed(\sprintf('%s answered %d.', $url, $response->getStatusCode()));
            }
            $content = '';
            foreach ($this->http->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (\strlen($content) > $maxBytes) {
                    $response->cancel();

                    throw new TemplateSourceFailed(\sprintf('%s is larger than %d MB.', $url, max(1, intdiv($maxBytes, 1_000_000))));
                }
            }
            // A redirect may not leave https.
            $final = $response->getInfo('url');
            if (!\is_string($final) || !str_starts_with($final, 'https://')) {
                throw new TemplateSourceFailed(\sprintf('%s redirected out of https.', $url));
            }

            return $content;
        } catch (HttpException $e) {
            throw new TemplateSourceFailed(\sprintf('%s cannot be fetched: %s', $url, $e->getMessage()), 0, $e);
        }
    }
}
