<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use Symfony\Component\Validator\Constraint;

/**
 * Extension point describing one kind of field (text, number, select...).
 *
 * Implementations are tagged "app.field_type" automatically. Later phases add
 * filter operators and grid rendering here.
 */
interface FieldTypeInterface
{
    /**
     * Unique machine name, stored in metadata_field.type.
     */
    public function name(): string;

    /**
     * Human name and one-line description, for editors choosing a type.
     */
    public function label(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function defaultSettings(): array;

    /**
     * The settings an editor offers, in order; their defaults are defaultSettings().
     *
     * @return list<SettingDefinition>
     */
    public function settingDefinitions(): array;

    /**
     * @param array<string, mixed> $settings settings as provided by the user, without defaults
     *
     * @return list<string> human readable errors, empty when the settings are valid
     */
    public function validateSettings(array $settings): array;

    /**
     * @param array<string, mixed> $settings valid settings, merged with defaults
     */
    public function columnSpec(array $settings): ColumnSpec;

    /**
     * Converts a raw value (form data, JSON scalar) to the PHP value stored in the column.
     * Empty values become null.
     *
     * @param array<string, mixed> $settings valid settings, merged with defaults
     *
     * @throws InvalidFieldValue when the value cannot represent this type
     */
    public function denormalize(mixed $value, array $settings): mixed;

    /**
     * Constraints applied to a denormalized, non-null value. "Required" and "unique"
     * are field level rules handled by the Validation module.
     *
     * @param array<string, mixed> $settings valid settings, merged with defaults
     *
     * @return list<Constraint>
     */
    public function constraints(array $settings): array;

    /**
     * @param array<string, mixed> $settings valid settings, merged with defaults
     */
    public function widget(array $settings): Widget;

    /**
     * The value a new record starts with when the field is not given, already denormalized;
     * null for none.
     *
     * @param array<string, mixed> $settings valid settings, merged with defaults
     */
    public function defaultValue(array $settings): mixed;

    /**
     * A write-only value (a password) is stored but never read back: it is left out of the
     * records, the grids, the exports, the search and the labels, and an empty value on
     * update keeps the stored one.
     */
    public function isWriteOnly(): bool;

    /**
     * Whether a field of this type may be unique. Not a write-only one (its value cannot be
     * compared), nor a file (every upload is a new file).
     */
    public function supportsUnique(): bool;

    /**
     * Converts a valid, denormalized value to what the column stores (e.g. a password hash).
     * Called after validation, so the constraints see the value as typed.
     *
     * @param array<string, mixed> $settings valid settings, merged with defaults
     */
    public function toStorage(mixed $value, array $settings): mixed;
}
