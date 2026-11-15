<?php

declare(strict_types=1);

namespace App\Module\Workflow\Domain\Repository;

use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Model\WorkflowVersion;
use Symfony\Component\Uid\Uuid;

interface WorkflowRepository
{
    /**
     * @return list<Workflow> in the list's order
     */
    public function all(): array;

    public function find(Uuid $id): ?Workflow;

    public function findByName(string $name): ?Workflow;

    /**
     * The position after the last one.
     */
    public function nextPosition(): int;

    /**
     * @return list<WorkflowVersion> newest first
     */
    public function versionsOf(Workflow $workflow): array;

    public function findVersion(Uuid $id): ?WorkflowVersion;

    public function save(Workflow|WorkflowVersion ...$items): void;

    public function remove(Workflow $workflow): void;
}
