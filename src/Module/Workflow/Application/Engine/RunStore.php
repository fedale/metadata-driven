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
    public function save(RunLog $run): void;

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
