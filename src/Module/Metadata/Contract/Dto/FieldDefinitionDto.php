<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * Read-only snapshot of a field definition, shared with other modules.
 */
final readonly class FieldDefinitionDto
{
    /**
     * @param array<string, mixed>   $settings  type specific settings, already resolved with defaults
     * @param FieldConditionDto|null $condition when the field applies; null: always
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
        public ?FieldConditionDto $condition = null,
    ) {
    }

    /**
     * Whether the field applies to a record with these values (keyed by field name): always,
     * unless its condition is not met.
     *
     * @param array<string, mixed> $values
     */
    public function appliesTo(array $values): bool
    {
        return null === $this->condition || $this->condition->isMetBy($values[$this->condition->field] ?? null);
    }

    /**
     * The relation settings, for a "relation" field; null for any other type.
     */
    public function relation(): ?RelationSettings
    {
        return RelationSettings::FIELD_TYPE === $this->type ? RelationSettings::fromSettings($this->settings) : null;
    }
}
