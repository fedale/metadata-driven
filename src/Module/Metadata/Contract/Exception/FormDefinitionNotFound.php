<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

final class FormDefinitionNotFound extends MetadataException
{
    public static function named(string $name): self
    {
        return new self(\sprintf('Form "%s" does not exist.', $name));
    }
}
