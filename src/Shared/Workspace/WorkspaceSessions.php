<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * Work done in a workspace's session from the system's: the steps that are a workspace's (its
 * permissions, its roles) when the system console creates or manages it; and the system's own,
 * from wherever. Each switch clears what the entity manager holds.
 */
interface WorkspaceSessions
{
    /**
     * Runs $work in the workspace's session, then goes back to the session it was in.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function within(Workspace $workspace, callable $work): mixed;

    /**
     * Runs $work in the system session (no workspace, every row seen), then goes back to the
     * session it was in: the system's own rows (its workflows) are read there.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function asSystem(callable $work): mixed;
}
