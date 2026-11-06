<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A data file whose content is at hand: a CSV file written by an export.
 */
final readonly class StringFile implements PackageFile
{
    public function __construct(
        private string $content,
    ) {
    }

    public function open()
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('No temporary stream can be opened.');
        }
        fwrite($stream, $this->content);
        rewind($stream);

        return $stream;
    }
}
