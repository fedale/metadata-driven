<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

use App\Shared\Message\Message;

final class FieldDefinitionNotFound extends MetadataException
{
    public static function named(string $objectName, string $fieldName): self
    {
        return new self(new Message('metadata.field_not_found', 'Field "%field%" does not exist on object "%object%".', ['field' => $fieldName, 'object' => $objectName]));
    }
}
