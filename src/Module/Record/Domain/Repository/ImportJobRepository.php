<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Repository;

use App\Module\Record\Domain\Model\ImportJob;
use App\Module\Record\Domain\Model\ImportJobStatus;

/**
 * The background imports of the current workspace.
 */
interface ImportJobRepository
{
    public function add(ImportJob $job): void;

    public function get(string $id): ?ImportJob;

    /**
     * A creator's imports of an object, the latest first.
     *
     * @return list<ImportJob>
     */
    public function latest(string $createdBy, string $object, int $limit): array;

    /**
     * Writes how far it went and its state; never its settings, which do not change.
     */
    public function save(ImportJob $job): void;

    /**
     * Moves it from one state to another, only if it is still in the first: two clicks on Import
     * queue one import.
     */
    public function transition(ImportJob $job, ImportJobStatus $from, ImportJobStatus $to): bool;

    /**
     * Asks the worker to stop it before its next batch.
     */
    public function requestStop(ImportJob $job): void;

    /**
     * Read again from the database: whether its creator asked to stop it meanwhile.
     */
    public function stopRequested(string $id): bool;

    public function delete(ImportJob $job): void;
}
