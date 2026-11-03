<?php

declare(strict_types=1);

namespace App\Module\File\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * The metadata of a stored file. Immutable: a new content is a new file.
 *
 * Several files may share one storage key (the same bytes uploaded twice); each keeps its own
 * name and date, and the bytes go only when no file uses the key any more.
 */
class File
{
    public function __construct(
        private Uuid $id,
        private string $storageKey,
        private string $originalName,
        private string $mimeType,
        private int $size,
        private string $sha256,
        private ?int $width,
        private ?int $height,
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
