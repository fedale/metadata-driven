<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * The type of an object's key, its "id" column: chosen when the object is made, fixed then (the
 * tables pointing at it hold keys of that type).
 *
 * Ids travel as strings whatever the type: the canonical text of the key ({@see normalize()}).
 * Anything read from the database goes through {@see fromDatabase()}: the driver returns an
 * integer key as a PHP int.
 */
enum KeyType: string
{
    /** A route requirement matching an id of any key type: a UUID or an integer. */
    public const string ROUTE_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|-?[0-9]{1,19}';

    // Made by the application (UUID v7) before the record is written.
    case Uuid = 'uuid';
    // Made by the database (an identity column) when the record is written.
    case Integer = 'integer';

    /**
     * The canonical text of an id of this type, or null when it is not one: a UUID in lower case,
     * an integer without sign "+" nor leading zeros, within the range of a bigint.
     */
    public function normalize(mixed $id): ?string
    {
        if (\is_int($id)) {
            return self::Integer === $this ? (string) $id : null;
        }
        if (!\is_string($id)) {
            return null;
        }
        $id = trim($id);

        return match ($this) {
            self::Uuid => Uuid::isValid($id) ? strtolower($id) : null,
            self::Integer => self::integer($id),
        };
    }

    /**
     * Leading zeros dropped ("-0" is "0"); null past the range of a bigint, which PHP's int
     * shares on 64 bits: a cast there saturates, and then differs from the text.
     */
    private static function integer(string $id): ?string
    {
        if (1 !== preg_match('/^(-?)0*(\d{1,19})$/', $id, $matches)) {
            return null;
        }
        $text = ('0' === $matches[2] ? '' : $matches[1]).$matches[2];

        return (string) (int) $text === $text ? $text : null;
    }

    /**
     * The canonical text of an id of any key type, or null when it is none: where the object is
     * not known (a link written by a workflow), the key type of the object checks it later.
     */
    public static function canonical(mixed $id): ?string
    {
        return self::Uuid->normalize($id) ?? self::Integer->normalize($id);
    }

    public function isValid(mixed $id): bool
    {
        return null !== $this->normalize($id);
    }

    /**
     * An id read from the database (a key column, a relation column, a link): its text, or null
     * for NULL.
     */
    public static function fromDatabase(mixed $id): ?string
    {
        return match (true) {
            \is_string($id) => $id,
            \is_int($id) => (string) $id,
            default => null,
        };
    }

    /**
     * The DBAL type binding a key of this type, and a column holding one.
     */
    public function dbalType(): string
    {
        return match ($this) {
            self::Uuid => 'guid',
            self::Integer => 'bigint',
        };
    }

    /**
     * The SQL type a text is cast to, to compare it with a key of this type.
     */
    public function sqlType(): string
    {
        return match ($this) {
            self::Uuid => 'UUID',
            self::Integer => 'BIGINT',
        };
    }
}
