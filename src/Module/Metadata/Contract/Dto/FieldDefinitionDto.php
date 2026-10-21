<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * Read-only snapshot of a field definition, shared with other modules.
 */
final readonly class FieldDefinitionDto
{
    /**
     * @param array<string, mixed> $settings type specific settings, already resolved with defaults
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $columnName,
        public string $type,
        public string $label,
        public bool $isRequired,
        public bool $isUnique,
        public array $settings,
        public int $position,
        public bool $isSystem,
    ) {
    }

    /**
     * The relation settings, for a "relation" field; null for any other type.
     */
    public function relation(): ?RelationSettings
    {
        return RelationSettings::FIELD_TYPE === $this->type ? RelationSettings::fromSettings($this->settings) : null;
    }
}
