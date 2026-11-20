<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

use App\Shared\Message\Message;

final class FormDefinitionNotFound extends MetadataException
{
    public static function named(string $name): self
    {
        return new self(new Message('metadata.form_not_found', 'Form "%name%" does not exist.', ['name' => $name]));
    }
}
