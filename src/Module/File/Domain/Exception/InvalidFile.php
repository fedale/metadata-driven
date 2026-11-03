<?php

declare(strict_types=1);

namespace App\Module\File\Domain\Exception;

use App\Module\File\Contract\Exception\FileException;

final class InvalidFile extends FileException
{
    public static function unreadable(string $originalName): self
    {
        return new self(\sprintf('The file "%s" cannot be read.', $originalName));
    }
}
