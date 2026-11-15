<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Record\Contract\Event\RecordWriteOrigin;

/**
 * Whether the record writes of an operation raise their events (RecordWriting, RecordWritten),
 * and where they come from. The default: they do, from no workflow.
 */
interface RecordEventScopeInterface
{
    /**
     * Runs $operation as part of a workflow's run: its writes carry the run as their origin, and
     * raise events only when $emitEvents.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function asRun(string $runId, int $depth, bool $emitEvents, callable $operation): mixed;

    /**
     * Runs $operation with no record event: work that is no one's write (a package's seeds).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function withoutEvents(callable $operation): mixed;

    public function emitsEvents(): bool;

    public function origin(): ?RecordWriteOrigin;
}
