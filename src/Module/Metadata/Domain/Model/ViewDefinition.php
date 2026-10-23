<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use App\Module\Metadata\Contract\Dto\ViewKind;
use Symfony\Component\Uid\Uuid;

/**
 * A named layout of an object for one kind of page (e.g. the record form).
 *
 * @internal create it through ObjectDefinition::saveView()
 */
class ViewDefinition
{
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private Uuid $id,
        private ObjectDefinition $object,
        private ViewKind $kind,
        private string $name,
        private string $template,
        private bool $isDefault,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function change(string $template): void
    {
        $this->template = $template;
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
