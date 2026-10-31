<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use App\Module\Metadata\Contract\Dto\ViewKind;
use Symfony\Component\Uid\Uuid;

/**
 * A named layout of an object for one kind of page (e.g. the record form).
 *
 * A form view is an HTML template; a grid view is a YAML source (the template, as written)
 * and its compiled definition.
 *
 * @internal create it through ObjectDefinition::saveView()
 */
class ViewDefinition
{
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed>|null $definition the compiled grid definition; null for a form view
     */
    public function __construct(
        private Uuid $id,
        private ObjectDefinition $object,
        private ViewKind $kind,
        private string $name,
        private string $template,
        private bool $isDefault,
        private ?array $definition = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * @param array<string, mixed>|null $definition
     */
    public function change(string $template, ?array $definition = null): void
    {
        $this->template = $template;
        $this->definition = $definition;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markDefault(bool $isDefault): void
    {
        if ($isDefault !== $this->isDefault) {
            $this->isDefault = $isDefault;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getObject(): ObjectDefinition
    {
        return $this->object;
    }

    public function getKind(): ViewKind
    {
        return $this->kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDefinition(): ?array
    {
        return $this->definition;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
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
