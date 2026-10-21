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
