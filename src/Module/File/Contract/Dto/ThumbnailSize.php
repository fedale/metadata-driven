<?php

declare(strict_types=1);

namespace App\Module\File\Contract\Dto;

/**
 * The thumbnails of an image; the value names them in URLs and in the storage.
 */
enum ThumbnailSize: string
{
    /** In a grid cell. */
    case Small = 'small';
    /** In a form, next to the name of the file. */
    case Medium = 'medium';

    /**
     * The side of the square the image fits in, keeping its proportions; in pixels.
     */
    public function pixels(): int
    {
        return match ($this) {
            self::Small => 64,
            self::Medium => 240,
        };
    }
}
