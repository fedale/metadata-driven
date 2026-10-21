<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use Symfony\Component\Uid\Uuid;

/**
 * A field of an object definition, mapped to one column of the object table.
 *
 * @internal create it through ObjectDefinition::addField()
 */
class FieldDefinition
{
    private string $columnName;
    private string $label;
    private bool $isSystem = false;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
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
