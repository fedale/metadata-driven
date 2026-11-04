<?php

declare(strict_types=1);

namespace App\Module\File\Application\Dto;

/**
 * A core.file row the GC deleted, or would delete.
 */
final readonly class CollectedFile
{
    public function __construct(
        public string $id,
        public string $originalName,
        public int $size,
        public \DateTimeImmutable $storedAt,
    ) {
    }
}
