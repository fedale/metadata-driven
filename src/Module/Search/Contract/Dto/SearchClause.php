<?php

declare(strict_types=1);

namespace App\Module\Search\Contract\Dto;

/**
 * One condition of a search: the value must match at least one of the fields (OR).
 * The clauses of a search are AND-ed.
 *
 * Framework-free on purpose: the storage that runs the search turns it into its own query.
 */
final readonly class SearchClause
{
    /**
     * @param list<string> $fields field names, never empty
     * @param string       $value  lowercase, never empty
     */
    public function __construct(
        public array $fields,
        public SearchMatch $match,
        public string $value,
    ) {
    }
}
