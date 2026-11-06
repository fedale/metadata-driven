<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A template: its manifest and its documents.
 */
final readonly class Package
{
    /**
     * @param list<Document>             $documents every document but the manifest
     * @param string|null                $directory where it was read from: its seeds' CSV files and
     *                                              files are there; null for a package built in memory
     * @param array<string, PackageFile> $files     data files not on disk yet (a package being
     *                                              exported), by their path in the package
     */
    public function __construct(
        public Document $manifest,
        public array $documents,
        public ?string $directory = null,
        public array $files = [],
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
     * Whether the package has a data file at this path, on disk or in memory.
     */
    public function has(string $path): bool
    {
        return isset($this->files[$path]) || null !== $this->file($path);
    }

    /**
     * A data file of the package, opened for reading; null when it has none at this path.
     *
     * @return resource|null closed by the caller
     */
    public function open(string $path)
    {
        if (isset($this->files[$path])) {
            return $this->files[$path]->open();
        }
        $file = $this->file($path);
        $stream = null === $file ? false : fopen($file, 'r');

        return false === $stream ? null : $stream;
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
