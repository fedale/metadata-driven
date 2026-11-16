<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use App\Module\Workflow\Contract\Dto\WorkflowSource;
use App\Module\Workflow\Contract\Exception\InvalidWorkflowSource;

/**
 * The workspace's workflows as packages carry them: exported, installed and upgraded as drafts.
 * A package never activates one: an admin does, whose permissions its steps then use.
 *
 * Definitions are arrays as the app stores them (trigger, steps), objects and the workflows a
 * run_workflow step calls by name.
 */
interface PackagedWorkflowsInterface
{
    /**
     * Every workflow of the current workspace, each with its current definition: its draft, else
     * its active version, else its latest one.
     *
     * @return array<string, WorkflowSource> by workflow id
     */
    public function all(): array;

    /**
     * A definition as the app stores it: its structure checked, its defaults filled in, written
     * the one way the app writes it, so that two definitions meaning the same compare equal.
     *
     * @param array<mixed> $definition
     *
     * @return array<string, mixed>
     *
     * @throws InvalidWorkflowSource its structure is wrong
     */
    public function canonical(array $definition): array;

    /**
     * A new workflow, its definition a draft: nothing runs until an admin activates it.
     *
     * @param array<mixed> $definition
     *
     * @return string its id
     *
     * @throws InvalidWorkflowSource a name missing or taken, a definition whose structure is wrong
     */
    public function create(string $name, ?string $description, array $definition): string;

    /**
     * Renames a workflow and writes its draft; its active version, if any, keeps running.
     *
     * @param array<mixed> $definition
     *
     * @throws InvalidWorkflowSource a name taken, a definition whose structure is wrong
     */
    public function draft(string $id, string $name, ?string $description, array $definition): void;

    /**
     * Stops a workflow's active version, if it has one: it runs no more.
     */
    public function deactivate(string $id): void;
}
