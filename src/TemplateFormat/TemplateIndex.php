<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * An index: the templates a repository offers, each version with the address of its archive,
 * its checksum and, signed, its signature. A static JSON file, in the manner of Helm
 * repositories: any web server can host one.
 *
 *     {
 *       "format": "metadata-driven/index/v1",
 *       "templates": {
 *         "acme/shop": {
 *           "1.0.0": { "url": "acme-shop-1.0.0.tar.gz", "sha256": "…", "requires": { "format": "metadata-driven/v1" } }
 *         }
 *       }
 *     }
 *
 * A relative "url" is resolved against the index's own address.
 */
final class TemplateIndex
{
    public const string FORMAT = 'metadata-driven/index/v1';

    /**
     * @param array<string, array<string, IndexEntry>> $entries name => version => entry
     */
    private function __construct(
        private array $entries,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @throws InvalidIndex
     */
    public static function fromJson(string $json, string $address): self
    {
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidIndex(\sprintf('The index at %s is not JSON: %s.', $address, $e->getMessage()), 0, $e);
        }
        if (!\is_array($data) || self::FORMAT !== ($data['format'] ?? null) || !\is_array($data['templates'] ?? null)) {
            throw new InvalidIndex(\sprintf('The index at %s is not of format %s.', $address, self::FORMAT));
        }

        $entries = [];
        foreach ($data['templates'] as $name => $versions) {
            $name = (string) $name;
            if (1 !== preg_match(Format::NAME_PATTERN, $name) || !\is_array($versions)) {
                throw new InvalidIndex(\sprintf('The index at %s lists "%s", which is no template name.', $address, $name));
            }
            foreach ($versions as $version => $entry) {
                $version = (string) $version;
                if (1 !== preg_match(Format::VERSION_PATTERN, $version) || !\is_array($entry)
                    || !\is_string($entry['url'] ?? null) || !\is_string($entry['sha256'] ?? null) || 1 !== preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])) {
                    throw new InvalidIndex(\sprintf('The index at %s has an invalid entry for %s %s: it needs a url and a sha256.', $address, $name, $version));
                }
                $requires = [];
                foreach (\is_array($entry['requires'] ?? null) ? $entry['requires'] : ['format' => Format::VERSION] as $required => $constraint) {
                    $requires[(string) $required] = \is_scalar($constraint) ? (string) $constraint : '';
                }
                $entries[$name][$version] = new IndexEntry(
                    $name,
                    $version,
                    self::resolve($entry['url'], $address),
                    $entry['sha256'],
                    \is_string($entry['signature'] ?? null) ? $entry['signature'] : null,
                    $requires,
                    true === ($entry['yanked'] ?? false),
                );
            }
        }

        return new self($entries);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->entries);
        sort($names);

        return $names;
    }

    /**
     * @return list<IndexEntry> oldest first
     */
    public function versions(string $name): array
    {
        $versions = array_values($this->entries[$name] ?? []);
        usort($versions, static fn (IndexEntry $a, IndexEntry $b): int => version_compare($a->version, $b->version));

        return $versions;
    }

    /**
     * The newest version this format can read that is not yanked.
     */
    public function latest(string $name): ?IndexEntry
    {
        $usable = array_filter($this->versions($name), static fn (IndexEntry $entry): bool => !$entry->yanked && Format::VERSION === ($entry->requires['format'] ?? null));

        return [] === $usable ? null : end($usable);
    }

    public function find(string $name, string $version): ?IndexEntry
    {
        return $this->entries[$name][$version] ?? null;
    }

    /**
     * The index with this entry added, or replacing the one of its version.
     */
    public function with(IndexEntry $entry): self
    {
        $entries = $this->entries;
        $entries[$entry->name][$entry->version] = $entry;

        return new self($entries);
    }

    /**
     * @param string $address where the index will be: urls under it are written relative to it
     */
    public function toJson(string $address = ''): string
    {
        $templates = [];
        foreach ($this->names() as $name) {
            foreach ($this->versions($name) as $entry) {
                $url = '' !== $address && str_starts_with($entry->url, self::base($address)) ? substr($entry->url, \strlen(self::base($address))) : $entry->url;
                $templates[$name][$entry->version] = array_filter([
                    'url' => $url,
                    'sha256' => $entry->sha256,
                    'signature' => $entry->signature,
                    'requires' => $entry->requires,
                    'yanked' => $entry->yanked ?: null,
                ], static fn (mixed $value): bool => null !== $value);
            }
        }

        return json_encode(['format' => self::FORMAT, 'templates' => $templates], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    }

    private static function resolve(string $url, string $address): string
    {
        if (1 === preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) || '' === $address) {
            return $url;
        }

        return self::base($address).ltrim($url, '/');
    }

    /**
     * The "directory" of an index's address: where its relative urls start.
     */
    private static function base(string $address): string
    {
        return substr($address, 0, (int) strrpos($address, '/') + 1);
    }
}
