<?php

declare(strict_types=1);

namespace App\Module\Workflow\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * A workflow of a workspace: when something happens to its records, run a series of steps. Its
 * definitions are its versions (WorkflowVersion).
 */
class Workflow
{
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private Uuid $id,
        private string $name,
        private ?string $description,
        /** The order among the workflows run by one event: the list's order, 0 first. */
        private int $position,
        /** Who created it, by email; null when no one signed in did (a command). */
        private ?string $createdBy,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function describe(string $name, ?string $description, \DateTimeImmutable $at): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->updatedAt = $at;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }
}
