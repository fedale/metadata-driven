<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * The first records a search found, and how many it found in all.
 */
final readonly class RecordSearchResult
{
    /**
     * @param list<RecordOutput> $records
     */
    public function __construct(
        public array $records,
        public int $total,
    ) {
    }
}
