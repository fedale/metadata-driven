<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;

/**
 * Rules for technical names that become SQL identifiers (tables, columns).
 *
 * The pattern is deliberately strict: names are always quoted in SQL as well,
 * but a predictable charset keeps identifiers portable and readable.
 */
final class MachineName
{
    public const string PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';
    public const int MIN_LENGTH = 2;
    // PostgreSQL truncates identifiers at 63 bytes; keep room for prefixes and index names.
    public const int MAX_LENGTH = 50;

    public static function assert(string $name): string
    {
        $length = \strlen($name);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $name)) {
            throw InvalidMetadataDefinition::invalidName($name, self::MAX_LENGTH);
        }

        return $name;
    }
}
