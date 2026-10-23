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
     * A write-only type (a password) is never read back: it cannot be unique, searched,
     * or name the records.
     */
    public function isWriteOnly(string $type): bool;

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
