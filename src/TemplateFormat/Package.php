<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A template: its manifest and its documents.
 */
final readonly class Package
{
    /**
     * @param list<Document> $documents every document but the manifest
     * @param string|null    $directory where it was read from: its seeds' CSV files and files
     *                                  are there; null for a package built in memory
     */
    public function __construct(
        public Document $manifest,
        public array $documents,
        public ?string $directory = null,
    ) {
    }

    public function name(): string
    {
        return $this->manifest->id;
    }

    public function version(): string
    {
        $version = $this->manifest->spec['version'] ?? null;

        return \is_string($version) ? $version : '';
    }

    /**
     * @return list<Document>
     */
    public function documentsOf(DocumentKind $kind): array
    {
        return array_values(array_filter($this->documents, static fn (Document $document): bool => $document->kind === $kind));
    }

    /**
     * The absolute path of a file of the package, given as written in a document
     * ("files/products/oslo.jpg"). Null when there is no such regular file inside the package:
     * an absolute path, "..", a symbolic link or a directory never resolve.
     */
    public function file(string $path): ?string
    {
        if (null === $this->directory || '' === $path || str_starts_with($path, '/') || str_contains($path, '\\')
            || \in_array('..', explode('/', $path), true)) {
            return null;
        }
        $file = $this->directory.'/'.$path;
        $root = realpath($this->directory);
        $real = realpath($file);
        if (false === $root || false === $real || !str_starts_with($real, $root.'/') || is_link($file) || !is_file($real)) {
            return null;
        }

        return $real;
    }
}
