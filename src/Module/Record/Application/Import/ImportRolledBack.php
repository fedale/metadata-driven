<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

/**
 * Thrown from inside a batch's transaction to roll it back: at the end of a dry run, or when
 * rows were refused. It carries the outcome out; it never leaves RecordImporter.
 *
 * @internal
 */
final class ImportRolledBack extends \RuntimeException
{
    public function __construct(
        public readonly ImportBatch $batch,
    ) {
        parent::__construct('Import rolled back.');
    }
}
