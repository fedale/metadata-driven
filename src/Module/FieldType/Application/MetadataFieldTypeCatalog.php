<?php

declare(strict_types=1);

namespace App\Module\FieldType\Application;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;

/**
 * Adapter exposing the field type registry through the port owned by the Metadata module.
 */
final class MetadataFieldTypeCatalog implements FieldTypeCatalogInterface
{
    public function __construct(
        private readonly FieldTypeRegistryInterface $registry,
    ) {
    }

    public function supports(string $type): bool
    {
        return $this->registry->has($type);
    }

    public function names(): array
    {
        return array_keys($this->registry->all());
    }

    public function defaultSettings(string $type): array
    {
        return $this->registry->has($type) ? $this->registry->get($type)->defaultSettings() : [];
    }

    public function isWriteOnly(string $type): bool
    {
        return $this->registry->has($type) && $this->registry->get($type)->isWriteOnly();
    }

    public function resolveSettings(string $type, array $settings): array
    {
        $fieldType = $this->registry->get($type);
        $errors = $fieldType->validateSettings($settings);
        if ([] !== $errors) {
            throw new InvalidFieldSettings($type, $errors);
        }

        return $settings + $fieldType->defaultSettings();
    }
}
