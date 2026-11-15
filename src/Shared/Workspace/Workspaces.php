<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * The workspace registry, core.workspace: readable by every module, whatever the current workspace.
 */
interface Workspaces
{
    public function bySlug(string $slug): ?Workspace;

    public function byId(string $id): ?Workspace;

    /**
     * @return list<Workspace> by slug
     */
    public function all(): array;
}
