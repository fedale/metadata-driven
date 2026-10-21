<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Exception;

final class RecordNotFound extends \DomainException
{
    public static function withId(string $objectName, string $id): self
    {
        return new self(\sprintf('Record "%s" of object "%s" does not exist.', $id, $objectName));
    }
}
