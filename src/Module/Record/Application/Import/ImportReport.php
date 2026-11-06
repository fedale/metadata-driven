<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

/**
 * What an import did, or, with dryRun, would have done before it was rolled back.
 */
final readonly class ImportReport
{
    public function __construct(
        public int $created,
        public int $updated,
        public bool $dryRun,
    ) {
    }
}
