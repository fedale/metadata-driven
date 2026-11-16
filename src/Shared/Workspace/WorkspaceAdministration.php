<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * Creates and manages workspaces, as the system: the system console and the workspace:*
 * commands.
 */
interface WorkspaceAdministration
{
    /**
     * @throws \InvalidArgumentException a slug that is malformed, reserved or taken
     */
    public function create(string $slug, string $name): Workspace;

    public function suspend(Workspace $workspace): void;

    public function resume(Workspace $workspace): void;

    /**
     * Puts the workspace on a plan of Plans.
     *
     * @throws \InvalidArgumentException no such plan
     */
    public function assignPlan(Workspace $workspace, string $plan): void;

    /**
     * Irreversible: its records, roles, definitions, members and files.
     */
    public function delete(Workspace $workspace): void;

    /**
     * @return list<array{workspace: Workspace, members: int, objects: int}> by slug
     */
    public function overview(): array;
}
