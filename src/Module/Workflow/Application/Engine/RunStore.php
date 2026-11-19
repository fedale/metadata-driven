<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

/**
 * Where the runs of the current workspace are kept.
 */
interface RunStore
{
    /**
     * Stores a run: a new one, or a resumed one again (its row updated, its new steps added),
     * and counts its steps in the day's usage.
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
     * The steps the current workspace's runs executed this calendar month (the database's
     * date), as the day's usage counts them.
     */
    public function stepsThisMonth(): int;

    /**
     * Counts emails sent by the current workspace's workflows, in today's usage (a negative number
     * gives back what was counted ahead); nothing outside a workspace (the system's).
     */
    public function countEmails(int $emails): void;

    /**
     * The emails the current workspace's workflows sent this calendar month.
     */
    public function emailsThisMonth(): int;

    /**
     * Deletes the runs started before then, with their steps, in the current workspace.
     *
     * @return int the runs deleted
     */
    public function deleteBefore(\DateTimeImmutable $before): int;
}
