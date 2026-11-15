<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Event;

/**
 * The run of a workflow a write comes from, when it does: how deep in a chain of runs it is.
 */
final readonly class RecordWriteOrigin
{
    public function __construct(
        public string $runId,
        public int $depth,
    ) {
    }
}
