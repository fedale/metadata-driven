<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\WorkspaceSessions;
use App\Shared\Workspace\WorkspaceSwitched;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Points the database session at a workspace (switchTo()), or back at its baseline (leave()):
 * no workspace, or the fallback one of app.workspace.fallback (the tests').
 *
 * What a switch leaves behind is forgotten: the entities the ORM holds, the current workspace's
 * cached row, and, through WorkspaceSwitched, the metadata kept in memory for the request.
 * Between two requests of a worker, reset() goes back to the baseline: a request that names no
 * workspace (a 404 before anything runs) can't read the previous one's.
 */
final class WorkspaceSwitcher implements ResetInterface, WorkspaceSessions
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectionCurrentWorkspace $current,
        private readonly WorkspaceSessionState $state,
        private readonly EventDispatcherInterface $events,
        #[Autowire('%app.workspace.fallback%')]
        private readonly string $fallback,
    ) {
    }

    public function within(Workspace $workspace, callable $work): mixed
    {
        $previous = $this->current->workspace();
        $system = $this->state->isSystem();
        $this->switchTo($workspace);
        try {
            return $work();
        } finally {
            if (null !== $previous) {
                $this->switchTo($previous);
            } elseif ($system) {
                $this->system();
            } else {
                $this->leave();
            }
        }
    }

    /**
     * The SQL putting a session at its baseline.
     */
    public static function baseline(string $fallback): string
    {
        return '' === $fallback ? WorkspaceSessionSql::none() : WorkspaceSessionSql::bySlug($fallback);
    }

    public function switchTo(Workspace $workspace): void
    {
        $this->state->setSystem(false);
        $this->connection->executeStatement(WorkspaceSessionSql::select($workspace));
        $this->forget($workspace);
    }

    /**
     * The system session, whatever the baseline: no workspace, and the row-level security
     * policies let it see and write every workspace's rows (the migrations, the workspace commands,
     * creating a workspace).
     */
    public function system(): void
    {
        // Never opens a connection: a command may run without a database, or before it exists
        // (doctrine:database:create). One opened later is the system's too (the middleware
        // reads the state).
        $this->state->setSystem(true);
        if ($this->connection->isConnected()) {
            $this->connection->executeStatement(WorkspaceSessionSql::system());
        }
        $this->forget(null);
    }

    /**
     * Back to the baseline: no workspace, or the fallback one (the tests').
     */
    public function leave(): void
    {
        $this->state->setSystem(false);
        $this->toBaseline();
        $this->forget(null);
    }

    public function reset(): void
    {
        $this->state->setSystem(false);
        $this->toBaseline();
        $this->current->reset();
    }

    private function toBaseline(): void
    {
        // A connection not open yet gets its baseline when it opens: no need to open it (a
        // command such as cache:clear may run without a database).
        if ($this->connection->isConnected()) {
            $this->connection->executeStatement(self::baseline($this->fallback));
        }
    }

    private function forget(?Workspace $workspace): void
    {
        $this->entityManager->clear();
        $this->current->reset();
        $this->events->dispatch(new WorkspaceSwitched($workspace));
    }
}
