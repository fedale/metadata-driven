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

    /**
     * The many-to-many settings, for a "many_to_many" field; null for any other type.
     */
    public function manyToMany(): ?ManyToManySettings
    {
        return ManyToManySettings::FIELD_TYPE === $this->type ? ManyToManySettings::fromSettings($this->settings) : null;
    }

    /**
     * The settings of a "file" field holding several files; null for any other field, a "file"
     * field holding one file included.
     */
    public function fileList(): ?FileListSettings
    {
        return FileListSettings::FIELD_TYPE === $this->type ? FileListSettings::fromSettings($this->settings) : null;
    }

    /**
     * Whether the field is a column of its object's table. A many-to-many field is not: its
     * values are links, kept in a table of their own (its columnName names that table); nor is a
     * file field holding several files, kept in a link table too. Every module reading or writing
     * columns skips the fields that answer false; the ones handling links tell the two apart with
     * manyToMany() and fileList().
     */
    public function storesColumn(): bool
    {
        return ManyToManySettings::FIELD_TYPE !== $this->type && null === $this->fileList();
    }

    /**
     * The object a relation or a many-to-many field points at; null for any other type.
     */
    public function target(): ?string
    {
        return $this->relation()->target ?? $this->manyToMany()?->target;
    }
}
