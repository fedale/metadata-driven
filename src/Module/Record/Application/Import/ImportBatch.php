<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

/**
 * What a batch of rows did, or would have done: with reasons, nothing of it was written.
 */
final readonly class ImportBatch
{
    /**
     * @param list<string> $reasons the rows refused, each with its line
     */
    public function __construct(
        public int $created,
        public int $updated,
        public array $reasons = [],
    ) {
    }
}
