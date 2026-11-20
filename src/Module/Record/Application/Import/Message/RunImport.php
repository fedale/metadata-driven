<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import\Message;

/**
 * The import of a checked background import, run by the worker (ImportHandler): its rows, batch by batch, each committed.
 */
final readonly class RunImport
{
    public function __construct(
        public string $workspaceId,
        public string $importId,
    ) {
    }
}
