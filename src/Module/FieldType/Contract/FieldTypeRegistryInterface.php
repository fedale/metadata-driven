<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract;

use App\Module\FieldType\Contract\Exception\UnknownFieldType;

interface FieldTypeRegistryInterface
{
    /**
     * @throws UnknownFieldType
     */
    public function get(string $name): FieldTypeInterface;

    public function has(string $name): bool;

    /**
     * @return array<string, FieldTypeInterface> keyed by type name
     */
    public function all(): array;
}
