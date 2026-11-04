<?php

declare(strict_types=1);

namespace App\Module\File\Application\Dto;

/**
 * What a run of the file GC deleted, or would delete on a dry run.
 */
final readonly class GarbageReport
{
    /**
     * @param list<CollectedFile> $files        the core.file rows, oldest first
     * @param int                 $contents     the stored contents (bytes under one SHA-256) no row uses any more
     * @param int                 $contentBytes their size, in bytes: the space freed
     * @param int                 $thumbnails   the thumbnails of those contents
     */
    public function __construct(
        public array $files,
        public int $contents,
        public int $contentBytes,
        public int $thumbnails,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->files && 0 === $this->contents && 0 === $this->thumbnails;
    }
}
