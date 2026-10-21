<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Exception;

final class UnknownFieldType extends \InvalidArgumentException
{
    public static function named(string $name): self
    {
        return new self(\sprintf('Unknown field type "%s".', $name));
    }
}
