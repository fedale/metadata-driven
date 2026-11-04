<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;

/**
 * Port implemented by the FieldType module, so that Metadata can validate field
 * definitions without depending on it.
 */
interface FieldTypeCatalogInterface
{
    public function supports(string $type): bool;

    /**
     * @return list<string>
     */
    public function names(): array;

    /**
     * The default settings of a type; empty for an unknown type.
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(string $type): array;

    /**
     * The settings that shape the column (or the foreign key) of a field of this type: they
     * cannot change once the field exists.
     *
     * @return list<string>
     */
    public function fixedSettings(string $type): array;

    /**
     * The values a field of this type can hold when it decides whether other fields apply (a
     * condition): the option values of a choice, "true" and "false" for a yes/no; null when the
     * type cannot decide.
     *
     * @param array<string, mixed> $settings resolved settings of the field
     *
     * @return list<string>|null
     */
    public function conditionValues(string $type, array $settings): ?array;

    /**
     * A write-only type (a password) is never read back: it cannot be unique, searched,
     * or name the records.
     */
    public function isWriteOnly(string $type): bool;

    /**
     * Whether a field of this type may be unique: not a write-only one, nor one whose value
     * is a reference that is new on every write (a file).
     */
    public function supportsUnique(string $type): bool;

    /**
     * Whether a grid can sort on a field of this type, search it and join it in a virtual
     * column: not a file (an id), nor a many-to-many field (a set of records).
     */
    public function isSortable(string $type): bool;

    /**
     * Validates the given settings and merges them with the type defaults.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     *
     * @throws InvalidFieldSettings
     */
    public function resolveSettings(string $type, array $settings): array;
}
