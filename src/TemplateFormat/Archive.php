<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A package as a .tar.gz archive, the form it is downloaded in.
 *
 * A small ustar reader and writer of its own rather than PharData: an archive comes from the
 * network, and this one accepts regular files and directories only (no links, no devices, no
 * extended headers), refuses any path leaving the target, checks every header, and stops past a
 * size and an entry count, whatever the compressed size claims. Written archives are
 * reproducible: no dates, owners or modes of the machine, entries sorted, so the same package
 * gives the same checksum.
 */
final class Archive
{
    private const int BLOCK = 512;
    private const int MAX_ENTRIES = 10000;

    /**
     * Writes every regular file of a package directory into an archive.
     *
     * @throws \RuntimeException a file cannot be read, is a link, or its path is too long
     */
    public function pack(string $directory, string $archive): void
    {
        $directory = rtrim($directory, '/');
        $files = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isDir()) {
                continue;
            }
            if ($entry->isLink() || !$entry->isFile()) {
                throw new \RuntimeException(\sprintf('"%s" is not a regular file: a template holds files only.', $entry->getPathname()));
            }
            $files[] = substr($entry->getPathname(), \strlen($directory) + 1);
        }
        sort($files);

        $out = gzopen($archive, 'wb9');
        if (false === $out) {
            throw new \RuntimeException(\sprintf('The archive "%s" cannot be written.', $archive));
        }
        try {
            foreach ($files as $path) {
                $content = file_get_contents($directory.'/'.$path);
                if (false === $content) {
                    throw new \RuntimeException(\sprintf('The file "%s" cannot be read.', $path));
                }
                gzwrite($out, self::header($path, \strlen($content)));
                gzwrite($out, $content.str_repeat("\0", (self::BLOCK - \strlen($content) % self::BLOCK) % self::BLOCK));
            }
            // The end of the archive: two empty blocks.
            gzwrite($out, str_repeat("\0", 2 * self::BLOCK));
        } finally {
            gzclose($out);
        }
    }

    /**
     * Extracts an archive into an empty directory.
     *
     * @param int $maxBytes the most its files may hold, uncompressed
     *
     * @throws InvalidPackage the archive is malformed, too large, or holds anything but files
     *                        and directories inside it
     */
    public function unpack(string $archive, string $directory, int $maxBytes): void
    {
        $in = gzopen($archive, 'rb');
        if (false === $in) {
            throw self::invalid('It cannot be read.');
        }
        try {
            $total = 0;
            $count = 0;
            while (true) {
                $header = self::read($in, self::BLOCK);
                if (str_repeat("\0", self::BLOCK) === $header) {
                    return;
                }
                if (++$count > self::MAX_ENTRIES) {
                    throw self::invalid(\sprintf('It has more than %d entries.', self::MAX_ENTRIES));
                }
                [$path, $type, $size] = self::parse($header);
                if ('5' === $type) {
                    continue;
                }
                $total += $size;
                if ($total > $maxBytes) {
                    throw self::invalid(\sprintf('Its files hold more than %d MB.', intdiv($maxBytes, 1_000_000)));
                }
                $target = $directory.'/'.$path;
                if (file_exists($target)) {
                    throw self::invalid(\sprintf('"%s" is in it twice.', $path));
                }
                if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0o775, true) && !is_dir(\dirname($target))) {
                    throw new \RuntimeException(\sprintf('The directory "%s" cannot be created.', \dirname($target)));
                }
                $file = fopen($target, 'x');
                if (false === $file) {
                    throw new \RuntimeException(\sprintf('The file "%s" cannot be written.', $target));
                }
                try {
                    for ($left = $size; $left > 0; $left -= \strlen($chunk)) {
                        $chunk = self::read($in, min($left, 65536));
                        fwrite($file, $chunk);
                    }
                } finally {
                    fclose($file);
                }
                self::read($in, (self::BLOCK - $size % self::BLOCK) % self::BLOCK);
            }
        } finally {
            gzclose($in);
        }
    }

    private static function header(string $path, int $size): string
    {
        [$prefix, $name] = \strlen($path) <= 100 ? ['', $path] : self::split($path);
        $header = str_pad($name, 100, "\0")
            .str_pad('0000644', 8, "\0")              // mode
            .str_pad('0000000', 8, "\0")              // uid
            .str_pad('0000000', 8, "\0")              // gid
            .str_pad(\sprintf('%011o', $size), 12, "\0")
            .str_pad('00000000000', 12, "\0")         // mtime: none, for a reproducible archive
            .str_repeat(' ', 8)                       // checksum, computed below
            .'0'                                      // a regular file
            .str_repeat("\0", 100)                    // link name
            ."ustar\x0000"
            .str_repeat("\0", 32 + 32 + 8 + 8)        // owner, group, device numbers
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);

        return substr_replace($header, \sprintf('%06o', self::checksum($header))."\0 ", 148, 8);
    }

    /**
     * @return array{string, string} the ustar prefix and name of a path longer than 100 bytes
     */
    private static function split(string $path): array
    {
        $cut = strrpos(substr($path, 0, 156), '/');
        if (false === $cut || \strlen($path) - $cut - 1 > 100) {
            throw new \RuntimeException(\sprintf('The path "%s" is too long for an archive.', $path));
        }

        return [substr($path, 0, $cut), substr($path, $cut + 1)];
    }

    /**
     * @return array{string, string, int} the entry's path, its type, its size
     */
    private static function parse(string $header): array
    {
        $stored = octdec(trim(substr($header, 148, 8), " \0"));
        if ($stored !== self::checksum(substr_replace($header, str_repeat(' ', 8), 148, 8))) {
            throw self::invalid('A header is corrupt.');
        }
        $type = $header[156];
        if (!\in_array($type, ['0', "\0", '5'], true)) {
            throw self::invalid('It holds something other than files and directories (a link, a device, an extended header).');
        }
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = 'ustar' === substr($header, 257, 5) ? rtrim(substr($header, 345, 155), "\0") : '';
        $path = ltrim(('' === $prefix ? '' : $prefix.'/').$name, '/');
        $path = str_starts_with($path, './') ? substr($path, 2) : $path;
        $path = rtrim($path, '/');
        if ('' === $path || str_starts_with(('' === $prefix ? '' : $prefix.'/').$name, '/') || str_contains($path, '\\') || str_contains($path, "\0")
            || \in_array('..', explode('/', $path), true)) {
            throw self::invalid(\sprintf('The path "%s" would leave the template.', $path));
        }
        $size = octdec(trim(substr($header, 124, 12), " \0"));

        return [$path, $type, \is_int($size) ? $size : 0];
    }

    private static function checksum(string $header): int
    {
        return array_sum(array_map(\ord(...), str_split($header)));
    }

    /**
     * @param resource $in
     */
    private static function read($in, int $length): string
    {
        if (0 === $length) {
            return '';
        }
        $data = '';
        while (\strlen($data) < $length) {
            $chunk = gzread($in, $length - \strlen($data));
            if (false === $chunk || '' === $chunk) {
                throw self::invalid('It ends too soon.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function invalid(string $why): InvalidPackage
    {
        return new InvalidPackage([new Problem('', '', 'The archive is invalid: '.$why)]);
    }
}
