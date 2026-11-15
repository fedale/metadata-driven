<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Database\WorkspaceScope;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The current workspace as the database session knows it: app.workspace_id, the setting the insert
 * trigger, the workspace filter and the policies read. Taking it from there rather than keeping an
 * id of its own means PHP and SQL can't disagree on the workspace.
 *
 * Read once (one row of core.workspace), and again after reset(): WorkspaceSwitcher resets it on
 * every switch, the kernel between two requests of a worker.
 */
final class ConnectionCurrentWorkspace implements CurrentWorkspace, ResetInterface
{
    private Workspace|false|null $workspace = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkspaceRegistry $registry,
    ) {
    }

    public function isSelected(): bool
    {
        return null !== $this->workspace();
    }

    public function id(): string
    {
        return $this->selected()->id;
    }

    public function dataSchema(): string
    {
        return $this->selected()->dataSchema;
    }

    /**
     * The current workspace's row, null when none is selected.
     */
    public function workspace(): ?Workspace
    {
        if (null === $this->workspace) {
            $id = $this->connection->fetchOne(\sprintf('SELECT %s::text', WorkspaceScope::CURRENT));
            $this->workspace = (\is_string($id) ? $this->registry->byId($id) : null) ?? false;
        }

        return false === $this->workspace ? null : $this->workspace;
    }

    public function reset(): void
    {
        $this->workspace = null;
    }

    private function selected(): Workspace
    {
        return $this->workspace() ?? throw new \LogicException('No workspace is selected: the database session has no app.workspace_id naming a row of core.workspace.');
    }
}
