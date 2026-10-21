<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Exception;

/**
 * Raised when a raw value cannot be converted to the field type; the message is shown to users.
 */
final class InvalidFieldValue extends \InvalidArgumentException
{
}
