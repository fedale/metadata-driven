<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

/**
 * Thrown from inside the import's transaction to roll it back: at the end of a dry run, or when
 * rows were refused. It carries the outcome out; it never leaves RecordImporter.
 *
 * @internal
 */
final class ImportRolledBack extends \RuntimeException
{
    /**
     * @param list<string> $reasons the rows refused; empty for a dry run
     */
    public function __construct(
        public readonly ImportReport $report,
        public readonly array $reasons = [],
    ) {
        parent::__construct('Import rolled back.');
    }
}
