<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * What an adoption did, or would do on a dry run: the SQL run on the table, in order, before
 * and after the metadata was written.
 */
final readonly class AdoptionReport
{
    /**
     * @param list<string> $statements
     */
    public function __construct(
        public string $objectName,
        public array $statements,
        public bool $dryRun,
    ) {
    }
}
