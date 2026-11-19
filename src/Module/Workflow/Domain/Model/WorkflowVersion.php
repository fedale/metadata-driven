<?php

declare(strict_types=1);

namespace App\Module\Workflow\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * One definition of a workflow: its trigger and its steps, as written (YAML) and as compiled
 * (the checked definition, JSON). Numbered from 1; at most one draft and one active per workflow.
 */
class WorkflowVersion
{
    private ?string $activatedBy = null;

    private ?\DateTimeImmutable $activatedAt = null;

    /**
     * @param array<string, mixed>|null $definition the compiled definition, once the version was checked; null for a
     *                                              draft not checked yet
     */
    public function __construct(
        private Uuid $id,
        private Workflow $workflow,
        private int $number,
        private VersionStatus $status,
        /** The definition as written, comments included. */
        private string $source,
        private ?array $definition,
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWorkflow(): Workflow
    {
        return $this->workflow;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStatus(): VersionStatus
    {
        return $this->status;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDefinition(): ?array
    {
        return $this->definition;
    }

    /**
     * Who activated it, by email: whose right to give the roles its steps act as is checked at
     * each run.
     */
    public function getActivatedBy(): ?string
    {
        return $this->activatedBy;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rewrite(string $source): void
    {
        if (VersionStatus::Draft !== $this->status) {
            throw new \LogicException('Only a draft is written.');
        }
        $this->source = $source;
        $this->definition = null;
    }

    /**
     * Follows the rename of an object or a field it names: its source and its compiled definition
     * rewritten together, whatever its status but archived (a version that ran no more stays as
     * it ran). Its activator stays: a rename gives no step more power.
     *
     * @param array<string, mixed>|null $definition the compiled definition, renamed; null when it had none
     */
    public function followRename(string $source, ?array $definition): void
    {
        if (VersionStatus::Archived === $this->status) {
            throw new \LogicException('An archived version stays as it ran.');
        }
        $this->source = $source;
        $this->definition = $definition;
    }

    /**
     * @param array<string, mixed> $definition the compiled definition
     */
    public function activate(array $definition, string $by, \DateTimeImmutable $at): void
    {
        if (VersionStatus::Draft !== $this->status && VersionStatus::Deactivated !== $this->status) {
            throw new \LogicException('Only a draft or a deactivated version is activated.');
        }
        $this->definition = $definition;
        $this->status = VersionStatus::Active;
        $this->activatedBy = $by;
        $this->activatedAt = $at;
    }

    public function deactivate(): void
    {
        $this->status = VersionStatus::Deactivated;
    }

    public function archive(): void
    {
        $this->status = VersionStatus::Archived;
    }
}
