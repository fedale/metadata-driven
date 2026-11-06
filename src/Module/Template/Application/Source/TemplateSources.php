<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Source;

use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use App\Module\Template\Domain\Model\TemplateSource;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
use App\TemplateFormat\Archive;
use App\TemplateFormat\Format;
use App\TemplateFormat\IndexEntry;
use App\TemplateFormat\InvalidIndex;
use App\TemplateFormat\InvalidPackage;
use App\TemplateFormat\TemplateIndex;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
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
 */
final class TemplateSources
{
    public const string CATALOG = 'catalog';
    private const string NAME_PATTERN = '~^[a-z][a-z0-9-]{0,49}$~';
    // An index is a list: a few MB is already a very large one.
    private const int MAX_INDEX_BYTES = 5_000_000;

    public function __construct(
        private readonly TemplateSourceRepository $sources,
        private readonly HttpClientInterface $http,
        private readonly Archive $archive,
        #[Autowire('%env(default::TEMPLATE_CATALOG_URL)%')]
        private readonly ?string $catalogUrl,
        #[Autowire(param: 'app.template.max_download_mb')]
        private readonly int $maxDownloadMb,
        #[Autowire(param: 'app.template.max_unpacked_mb')]
        private readonly int $maxUnpackedMb,
    ) {
    }

    /**
     * @return list<array{name: string, url: string}> the catalog first
     */
    public function indexes(): array
    {
        $indexes = null === $this->catalogUrl || '' === $this->catalogUrl ? [] : [['name' => self::CATALOG, 'url' => $this->catalogUrl]];
        foreach ($this->sources->all() as $source) {
            $indexes[] = ['name' => $source->getName(), 'url' => $source->getUrl()];
        }

        return $indexes;
    }

    /**
     * Adds an index, once it has been read and found valid.
     *
     * @throws TemplateSourceFailed
     */
    public function add(string $name, string $url): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name) || self::CATALOG === $name) {
            throw new TemplateSourceFailed(\sprintf('"%s" cannot name an index: lowercase letters, digits and hyphens, and not "%s".', $name, self::CATALOG));
        }
        if (null !== $this->sources->findByName($name)) {
            throw new TemplateSourceFailed(\sprintf('An index is already named "%s".', $name));
        }
        $this->index($url);
        $this->sources->save(new TemplateSource(Uuid::v7(), $name, $url, new \DateTimeImmutable()));
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
                    $found[] = new LocatedTemplate($source['name'], $source['url'], $latest);
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

        return new LocatedTemplate($index['name'], $index['url'], $entry);
    }

    /**
     * Downloads a version's archive, checks it, and unpacks it into a new temporary directory,
     * for the caller to read, then discard().
     *
     * @throws TemplateSourceFailed
     */
    public function download(IndexEntry $entry): string
    {
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
