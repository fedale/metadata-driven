<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Source;

use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Module\Package\Domain\Model\PackageSource;
use App\Module\Package\Domain\Repository\PackageSourceRepository;
use App\Shared\Message\Message;
use App\Shared\Workspace\CurrentWorkspace;
use Fedale\PackageFormat\Archive;
use Fedale\PackageFormat\Constraint;
use Fedale\PackageFormat\Format;
use Fedale\PackageFormat\IndexEntry;
use Fedale\PackageFormat\InvalidIndex;
use Fedale\PackageFormat\InvalidPackage;
use Fedale\PackageFormat\RepositoryIndex;
use Fedale\PackageFormat\Signature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The indexes this instance looks into, and the packages they offer: the package catalog when
 * configured (PACKAGE_CATALOG_URL), then the indexes added with metadata:package:repo.
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
final class PackageSources
{
    public const string CATALOG = 'catalog';
    private const string NAME_PATTERN = '~^[a-z][a-z0-9-]{0,49}$~';
    // An index is a list: a few MB is already a very large one.
    private const int MAX_INDEX_BYTES = 5_000_000;

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly PackageSourceRepository $sources,
        private readonly CurrentWorkspace $workspace,
        HttpClientInterface $http,
        private readonly Archive $archive,
        #[Autowire('%env(default::PACKAGE_CATALOG_URL)%')]
        private readonly ?string $catalogUrl,
        #[Autowire('%env(default::PACKAGE_CATALOG_KEY)%')]
        private readonly ?string $catalogKey,
        #[Autowire(param: 'app.package.max_download_mb')]
        private readonly int $maxDownloadMb,
        #[Autowire(param: 'app.package.max_unpacked_mb')]
        private readonly int $maxUnpackedMb,
        #[Autowire(param: 'app.package.allow_private_networks')]
        bool $allowPrivateNetworks,
    ) {
        // An index names the addresses of its archives, and may redirect: without this, a hostile
        // index could make the server request its own network (a metadata service, an admin page).
        $this->http = $allowPrivateNetworks ? $http : new NoPrivateNetworkHttpClient($http);
    }

    /**
     * @return list<array{name: string, url: string, key: string|null, shared: bool}> the catalog
     *                                                                                first; shared: offered to every workspace (the catalog, those of system:package:repo)
     *
     * @throws PackageSourceFailed the catalog is configured without its key
     */
    public function indexes(): array
    {
        $indexes = [];
        if (null !== $this->catalogUrl && '' !== $this->catalogUrl) {
            // The catalog is signed, always: an unsigned catalog would be anybody's.
            if (null === $this->catalogKey || !Signature::isPublicKey($this->catalogKey)) {
                throw new PackageSourceFailed(new Message('package.source.catalog_key_missing', 'PACKAGE_CATALOG_URL is set without PACKAGE_CATALOG_KEY, the public key the catalog signs with.'));
            }
            $indexes[] = ['name' => self::CATALOG, 'url' => $this->catalogUrl, 'key' => $this->catalogKey, 'shared' => true];
        }
        foreach ($this->sources->all() as $source) {
            $indexes[] = ['name' => $source->getName(), 'url' => $source->getUrl(), 'key' => $source->getPublicKey(), 'shared' => $this->sources->isShared($source)];
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
     * @throws PackageSourceFailed
     */
    public function add(string $name, string $url, ?string $publicKey = null, bool $unsigned = false): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name) || self::CATALOG === $name) {
            throw new PackageSourceFailed(new Message('package.source.invalid_index_name', '"%name%" cannot name an index: lowercase letters, digits and hyphens, and not "%catalog%".', ['name' => $name, 'catalog' => self::CATALOG]));
        }
        if ($this->sources->isNameTaken($name)) {
            throw new PackageSourceFailed(new Message('package.source.index_name_taken', 'An index is already named "%name%".', ['name' => $name]));
        }
        if (null === $publicKey && !$unsigned) {
            throw new PackageSourceFailed(new Message('package.source.key_or_unsigned', 'Give the public key its entries are signed with (--key), or accept it unsigned (--unsigned), trusting its checksums only.'));
        }
        if (null !== $publicKey && !Signature::isPublicKey($publicKey)) {
            throw new PackageSourceFailed(new Message('package.source.not_a_key', 'This is not an Ed25519 public key in base64.'));
        }
        $index = $this->index($url);
        if (null !== $publicKey) {
            foreach ($index->names() as $package) {
                foreach ($index->versions($package) as $entry) {
                    if (!Signature::verify($entry, $publicKey)) {
                        throw new PackageSourceFailed(new Message('package.source.entry_not_signed', '%name% %version% in this index is not signed with this key.', ['name' => $entry->name, 'version' => $entry->version]));
                    }
                }
            }
        }
        $this->sources->save(new PackageSource(Uuid::v7(), $name, $url, $publicKey, new \DateTimeImmutable()));
    }

    /**
     * @throws PackageSourceFailed
     */
    public function remove(string $name): void
    {
        $source = $this->sources->findByName($name) ?? throw new PackageSourceFailed(new Message('package.source.no_index_named', 'No index is named "%name%".', ['name' => $name]));
        if ($this->workspace->isSelected() && $this->sources->isShared($source)) {
            throw new PackageSourceFailed(new Message('package.source.shared_index', '"%name%" is offered to every workspace: only the system admin removes it (system:package:repo remove %name%).', ['name' => $name]));
        }
        $this->sources->remove($source);
    }

    /**
     * @throws PackageSourceFailed
     */
    public function index(string $url): RepositoryIndex
    {
        try {
            return RepositoryIndex::fromJson($this->fetch($url, self::MAX_INDEX_BYTES), $url);
        } catch (InvalidIndex $e) {
            // The format's problems are English only.
            throw new PackageSourceFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * The newest version of every package whose name holds the term, in every index.
     *
     * @return list<LocatedPackage>
     *
     * @throws PackageSourceFailed
     */
    public function search(string $term = ''): array
    {
        $found = [];
        foreach ($this->indexes() as $source) {
            $index = $this->index($source['url']);
            foreach ($index->names() as $name) {
                $latest = $index->latest($name);
                if (null !== $latest && ('' === $term || str_contains($name, strtolower($term)))) {
                    $found[] = new LocatedPackage($source['name'], $source['url'], $latest, $source['key']);
                }
            }
        }

        return $found;
    }

    /**
     * A version of a package, in the one index offering it.
     *
     * @param string|null $version null for the newest one, yanked versions left out
     * @param string|null $source  the name of the index to look in; null for all of them
     * @param string|null $prefer  the address of an index to look in first (where it was installed from)
     *
     * @throws PackageSourceFailed
     */
    public function locate(string $name, ?string $version = null, ?string $source = null, ?string $prefer = null): LocatedPackage
    {
        $indexes = $this->indexes();
        if (null !== $source) {
            $indexes = array_values(array_filter($indexes, static fn (array $index): bool => $index['name'] === $source));
            if ([] === $indexes) {
                throw new PackageSourceFailed(new Message('package.source.no_index_named_list', 'No index is named "%name%" (metadata:package:repo list).', ['name' => $source]));
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
            throw new PackageSourceFailed(new Message('package.source.not_offered', 'No index offers %name%.', ['name' => $name]));
        }
        if (\count($found) > 1) {
            throw new PackageSourceFailed(new Message('package.source.offered_by_many', '%name% is offered by %indexes%: choose one with --repo.', ['name' => $name, 'indexes' => self::indexNames($found)]));
        }

        [$index, $read] = $found[0];
        $entry = null === $version ? $read->latest($name) : $read->find($name, $version);
        if (null === $entry) {
            throw new PackageSourceFailed(null === $version ? new Message('package.source.no_version', '%index% offers no version of %name% for format %formats% that is not yanked.', ['index' => $index['name'], 'name' => $name, 'formats' => self::formats()]) : new Message('package.source.no_such_version', '%index% offers no version %version% of %name%.', ['index' => $index['name'], 'version' => $version, 'name' => $name]));
        }

        return new LocatedPackage($index['name'], $index['url'], $entry, $index['key']);
    }

    /**
     * The newest version of a package a constraint allows, yanked ones and those of another
     * format left out: what a package requiring it gets installed along with it. The index the
     * requiring package came from is looked in first; when it doesn't offer the package, every
     * index, and then only one may offer it.
     *
     * @param string $prefer the address of the index the requiring package came from
     *
     * @throws PackageSourceFailed
     */
    public function locateFitting(string $name, Constraint $constraint, string $prefer): LocatedPackage
    {
        $indexes = $this->indexes();
        $preferred = array_values(array_filter($indexes, static fn (array $index): bool => $index['url'] === $prefer));
        $found = [];
        foreach ($preferred as $index) {
            $read = $this->index($index['url']);
            if ([] !== $read->versions($name)) {
                $found[] = [$index, $read];
            }
        }
        if ([] === $found) {
            foreach ($indexes as $index) {
                if ($index['url'] === $prefer) {
                    continue;
                }
                $read = $this->index($index['url']);
                if ([] !== $read->versions($name)) {
                    $found[] = [$index, $read];
                }
            }
        }
        if ([] === $found) {
            throw new PackageSourceFailed(new Message('package.source.required_not_offered', 'No index offers %name%, which is required in version %constraint%.', ['name' => $name, 'constraint' => (string) $constraint]));
        }
        if (\count($found) > 1) {
            throw new PackageSourceFailed(new Message('package.source.required_offered_by_many', '%name%, which is required, is offered by %indexes%: install it first, choosing one with --repo.', ['name' => $name, 'indexes' => self::indexNames($found)]));
        }

        [$index, $read] = $found[0];
        $fitting = array_values(array_filter(
            $read->versions($name),
            static fn (IndexEntry $entry): bool => !$entry->yanked && \in_array($entry->requires['format'] ?? null, Format::READS, true) && $constraint->allows($entry->version),
        ));
        if ([] === $fitting) {
            throw new PackageSourceFailed(new Message('package.source.no_fitting_version', '%index% offers no version of %name% that fits %constraint% and is not yanked.', ['index' => $index['name'], 'name' => $name, 'constraint' => (string) $constraint]));
        }
        usort($fitting, static fn (IndexEntry $a, IndexEntry $b): int => version_compare($b->version, $a->version));

        return new LocatedPackage($index['name'], $index['url'], $fitting[0], $index['key']);
    }

    /**
     * Downloads a version's archive, checks it, and unpacks it into a new temporary directory,
     * for the caller to read, then discard().
     *
     * @throws PackageSourceFailed
     */
    public function download(LocatedPackage $located): string
    {
        $entry = $located->entry;
        if (null !== $located->publicKey && !Signature::verify($entry, $located->publicKey)) {
            throw new PackageSourceFailed(new Message('package.source.not_signed', '%name% %version% is not signed with the key of %index%: it was not downloaded.', ['name' => $entry->name, 'version' => $entry->version, 'index' => $located->source]));
        }
        if (!\in_array($entry->requires['format'] ?? null, Format::READS, true)) {
            throw new PackageSourceFailed(new Message('package.source.wrong_format', '%name% %version% is of format %format%; this instance reads %formats%.', ['name' => $entry->name, 'version' => $entry->version, 'format' => $entry->requires['format'] ?? '?', 'formats' => self::formats()]));
        }
        $content = $this->fetch($entry->url, $this->maxDownloadMb * 1_000_000);
        if (!hash_equals($entry->sha256, hash('sha256', $content))) {
            throw new PackageSourceFailed(new Message('package.source.checksum_differs', 'The archive of %name% %version% is not the one its index lists: its checksum differs. Nothing was unpacked.', ['name' => $entry->name, 'version' => $entry->version]));
        }

        $directory = sys_get_temp_dir().'/md-package-'.bin2hex(random_bytes(8));
        $archive = $directory.'.tar.gz';
        file_put_contents($archive, $content);
        try {
            $this->archive->unpack($archive, $directory, $this->maxUnpackedMb * 1_000_000);
        } catch (InvalidPackage $e) {
            $this->discard($directory);

            // The format's problem is English only.
            throw new PackageSourceFailed(new Message('package.source.invalid_archive', '%name% %version%: %problem%', ['name' => $entry->name, 'version' => $entry->version, 'problem' => $e->getMessage()]), 0, $e);
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
     * @throws PackageSourceFailed
     */
    private function fetch(string $url, int $maxBytes): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new PackageSourceFailed(new Message('package.source.not_https', '%url% is not an https address: packages are fetched over https only.', ['url' => $url]));
        }
        try {
            $response = $this->http->request('GET', $url, ['max_redirects' => 5, 'timeout' => 30]);
            if (200 !== $response->getStatusCode()) {
                throw new PackageSourceFailed(new Message('package.source.http_status', '%url% answered %status%.', ['url' => $url, 'status' => $response->getStatusCode()]));
            }
            $content = '';
            foreach ($this->http->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (\strlen($content) > $maxBytes) {
                    $response->cancel();

                    throw new PackageSourceFailed(new Message('package.source.too_large', '%url% is larger than %max% MB.', ['url' => $url, 'max' => max(1, intdiv($maxBytes, 1_000_000))]));
                }
            }
            // A redirect may not leave https.
            $final = $response->getInfo('url');
            if (!\is_string($final) || !str_starts_with($final, 'https://')) {
                throw new PackageSourceFailed(new Message('package.source.redirected', '%url% redirected out of https.', ['url' => $url]));
            }

            return $content;
        } catch (HttpException $e) {
            // The HTTP client's error is technical, and English only.
            throw new PackageSourceFailed(new Message('package.source.unreachable', '%url% cannot be fetched: %error%', ['url' => $url, 'error' => $e->getMessage()]), 0, $e);
        }
    }

    /**
     * "catalog and acme": the indexes offering a package, the words translatable.
     *
     * @param list<array{array{name: string}, RepositoryIndex}> $found
     */
    private static function indexNames(array $found): string|Message
    {
        $names = array_map(static fn (array $pair): string => $pair[0]['name'], $found);
        $joined = array_shift($names) ?? '';
        foreach ($names as $name) {
            $joined = new Message('package.source.and', '%first% and %second%', ['first' => $joined, 'second' => $name]);
        }

        return $joined;
    }

    /**
     * The formats this instance reads, "metadata-driven/v1 or metadata-driven/v2".
     */
    private static function formats(): Message
    {
        $formats = Format::READS;
        $joined = array_shift($formats);
        foreach ($formats as $format) {
            $joined = new Message('package.source.or', '%first% or %second%', ['first' => $joined, 'second' => $format]);
        }

        return $joined;
    }
}
