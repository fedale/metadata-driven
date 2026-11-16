<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

/**
 * Where the runs of the current workspace are kept.
 */
interface RunStore
{
    /**
     * Stores a run with its steps, and counts its steps in the day's usage.
     */
    /**
     * Stores a run: a new one, or a resumed one again (its row updated, its new steps added).
     */
    public function save(RunLog $run): void;

    /**
     * A run of the current workspace (or the system's) that waits.
     */
    public function waiting(string $id): ?WaitingRun;

    /**
     * The runs started since then, in the current workspace.
     */
    public function countSince(\DateTimeImmutable $since): int;

    /**
     * Deletes the runs started before then, with their steps, in the current workspace.
     *
     * @return int the runs deleted
     */
    public function deleteBefore(\DateTimeImmutable $before): int;
}
