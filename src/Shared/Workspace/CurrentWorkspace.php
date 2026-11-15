<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * The workspace the current request or command works for (see docs/internal/multi-tenancy-plan.md).
 *
 * Its definitions, files and packages are rows of the core tables carrying its id
 * ({@see \App\Shared\Database\WorkspaceScope}); its records are the tables of a schema of its own,
 * {@see dataSchema()}.
 */
interface CurrentWorkspace
{
    /**
     * Whether a workspace is selected. None is the system session: the migrations, the workspace
     * commands; it reads no workspace's rows through the ORM and writes none.
     */
    public function isSelected(): bool;

    /**
     * The workspace's id, a UUID.
     *
     * @throws \LogicException when no workspace is selected
     */
    public function id(): string;

    /**
     * The PostgreSQL schema holding the workspace's record tables, unquoted.
     *
     * @return non-empty-string
     *
     * @throws \LogicException when no workspace is selected
     */
    public function dataSchema(): string;
}
