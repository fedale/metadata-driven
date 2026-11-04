<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use App\Module\Metadata\Contract\Dto\ManyToManySettings;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use Symfony\Component\Uid\Uuid;

/**
 * A field of an object definition, mapped to one column of the object table; a many-to-many
 * field to a link table of its own instead.
 *
 * An archived field is out of the application (forms, grids, validation) while its column and
 * its values stay: it can be restored, or purged for good.
 *
 * @internal create it through ObjectDefinition::addField()
 */
class FieldDefinition
{
    private string $columnName;
    private string $label;
    private bool $isSystem = false;
    private bool $isArchived = false;
    /** @var array{field: string, values: list<string>}|null */
    private ?array $condition = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private Uuid $id,
        private ObjectDefinition $object,
        private string $name,
        private string $type,
        string $label,
        private bool $isRequired,
        private bool $isUnique,
        private array $settings,
        private int $position,
    ) {
        $this->columnName = $name;
        $this->relabel($label);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function relabel(string $label): void
    {
        $label = trim($label);
        if ('' === $label) {
            throw InvalidMetadataDefinition::emptyLabel();
        }

        $this->label = $label;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * The column follows the name: the caller renames it in the same transaction.
     *
     * @internal use ObjectDefinition::renameField()
     */
    public function rename(string $name): void
    {
        $this->name = $name;
        $this->columnName = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @internal use ObjectDefinition::archiveField() and restoreField()
     */
    public function markArchived(bool $isArchived): void
    {
        $this->isArchived = $isArchived;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $settings already validated by the field type
     *
     * @internal use ObjectDefinition::changeFieldSettings() and renameField()
     */
    public function changeSettings(array $settings): void
    {
        $this->settings = $settings;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array{field: string, values: list<string>}|null $condition checked by ObjectDefinition::setFieldCondition()
     *
     * @internal
     */
    public function applyWhen(?array $condition): void
    {
        $this->condition = $condition;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array{field: string, values: list<string>}|null
     */
    public function getCondition(): ?array
    {
        return $this->condition;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getObject(): ObjectDefinition
    {
        return $this->object;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getColumnName(): string
    {
        return $this->columnName;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function isUnique(): bool
    {
        return $this->isUnique;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * A many-to-many field has no column: its links live in a table of their own.
     */
    public function isManyToMany(): bool
    {
        return ManyToManySettings::FIELD_TYPE === $this->type;
    }

    /**
     * The object a relation or a many-to-many field points at; null for any other type.
     */
    /**
     * The junction object a many-to-many field goes through; null for any other field.
     */
    public function getThrough(): ?string
    {
        $through = $this->isManyToMany() ? ($this->settings['through'] ?? null) : null;

        return \is_string($through) ? $through : null;
    }

    public function getTarget(): ?string
    {
        if (RelationSettings::FIELD_TYPE !== $this->type && !$this->isManyToMany()) {
            return null;
        }
        $target = $this->settings['target'] ?? null;

        return \is_string($target) ? $target : null;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
