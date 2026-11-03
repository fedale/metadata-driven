<?php

declare(strict_types=1);

namespace App\Module\File\Contract\Dto;

final readonly class Thumbnail
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {
    }
}
