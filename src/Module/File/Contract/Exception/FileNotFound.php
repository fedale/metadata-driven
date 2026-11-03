<?php

declare(strict_types=1);

namespace App\Module\File\Contract\Exception;

final class FileNotFound extends FileException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('File "%s" does not exist.', $id));
    }

    public static function bytesMissing(string $id, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The content of file "%s" is missing from the storage.', $id), 0, $previous);
    }
}
