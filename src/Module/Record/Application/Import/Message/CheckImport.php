<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import\Message;

/**
 * The check of a background import, run by the worker (ImportHandler): its rows, batch by batch, each rolled back.
 */
final readonly class CheckImport
{
    public function __construct(
        public string $workspaceId,
        public string $importId,
    ) {
    }
}
