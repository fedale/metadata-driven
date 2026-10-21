<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

final class FieldDefinitionNotFound extends MetadataException
{
    public static function named(string $objectName, string $fieldName): self
    {
        return new self(\sprintf('Field "%s" does not exist on object "%s".', $fieldName, $objectName));
    }
}
