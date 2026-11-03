<?php

declare(strict_types=1);

namespace App\Module\File\Contract\Dto;

final readonly class StoredFile
{
    public function __construct(
        public string $id,
        public string $originalName,
        public string $mimeType,
        /** In bytes. */
        public int $size,
        public string $sha256,
        /** In pixels, images only. */
        public ?int $width,
        public ?int $height,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function isImage(): bool
    {
        return null !== $this->width;
    }
}
