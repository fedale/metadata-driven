<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

use App\Shared\Message\Message;

final class ObjectDefinitionNotFound extends MetadataException
{
    public static function named(string $name): self
    {
        return new self(new Message('metadata.object_not_found', 'Object "%name%" does not exist.', ['name' => $name]));
    }
}
