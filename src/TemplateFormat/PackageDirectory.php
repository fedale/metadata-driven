<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A package as a directory: the manifest at its root, every other document in a file the
 * manifest's resources match. Archives (.tar.gz) come with the indexes.
 */
final class PackageDirectory
{
    public function __construct(
        private readonly DocumentCodec $codec,
    ) {
    }

    /**
     * Reads every document; the package is checked by PackageChecker afterwards.
     *
     * @throws InvalidPackage a file cannot be read, or its envelope is broken
     */
    public function read(string $directory): Package
    {
        $directory = rtrim($directory, '/');
        $manifestPath = $directory.'/'.Format::MANIFEST;
        if (!is_file($manifestPath)) {
            throw new InvalidPackage([new Problem(Format::MANIFEST, '', \sprintf('No manifest in "%s".', $directory))]);
        }
        $manifest = $this->codec->decode((string) file_get_contents($manifestPath), Format::MANIFEST);
        if (DocumentKind::Template !== $manifest->kind) {
            throw new InvalidPackage([new Problem(Format::MANIFEST, 'kind', 'The manifest is of kind Template.')]);
        }

        $problems = [];
        $documents = [];
        foreach ($this->resources($manifest) as $i => $pattern) {
            // The schema allows "dir/pattern.yaml" only: no "..", no absolute path, no subdirectories.
            if (str_contains($pattern, '..') || str_starts_with($pattern, '/')) {
                $problems[] = new Problem(Format::MANIFEST, 'spec.resources.'.$i, 'A resource stays inside the package.');
                continue;
            }
            foreach (glob($directory.'/'.$pattern) ?: [] as $file) {
                $path = substr($file, \strlen($directory) + 1);
                if (is_link($file) || !is_file($file)) {
                    $problems[] = new Problem($path, '', 'Not a regular file.');
                    continue;
                }
                try {
                    $documents[$path] = $this->codec->decode((string) file_get_contents($file), $path);
                } catch (InvalidPackage $e) {
                    $problems = [...$problems, ...$e->problems];
                }
            }
        }
        if ([] !== $problems) {
            throw new InvalidPackage($problems);
        }
        ksort($documents);

        return new Package($manifest, array_values($documents), $directory);
    }

    /**
     * Writes a package into a directory that does not exist yet, or is empty.
     *
     * @throws \RuntimeException the directory is not empty, or cannot be written
     */
    public function write(Package $package, string $directory): void
    {
        $directory = rtrim($directory, '/');
        if (is_dir($directory) && [] !== array_diff((array) scandir($directory), ['.', '..'])) {
            throw new \RuntimeException(\sprintf('The directory "%s" is not empty: a template is written into a new or empty one.', $directory));
        }
        foreach ([$package->manifest, ...$package->documents] as $document) {
            $file = $directory.'/'.$document->path;
            if (!is_dir(\dirname($file)) && !mkdir(\dirname($file), 0o775, true) && !is_dir(\dirname($file))) {
                throw new \RuntimeException(\sprintf('The directory "%s" cannot be created.', \dirname($file)));
            }
            if (false === file_put_contents($file, $this->codec->encode($document))) {
                throw new \RuntimeException(\sprintf('The file "%s" cannot be written.', $file));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resources(Document $manifest): array
    {
        $resources = $manifest->spec['resources'] ?? [];

        return \is_array($resources) ? array_values(array_filter($resources, is_string(...))) : [];
    }
}
