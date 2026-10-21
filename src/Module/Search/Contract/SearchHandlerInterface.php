<?php

declare(strict_types=1);

namespace App\Module\Search\Contract;

use App\Module\Search\Contract\Dto\SearchClause;

/**
 * Extension point: turns what the user typed into search clauses.
 *
 * Implementations are tagged "app.search_handler" automatically; an object picks one by
 * name in its metadata.
 */
interface SearchHandlerInterface
{
    /**
     * Unique machine name, stored in metadata_object.search_handler.
     */
    public function name(): string;

    /**
     * @param list<string> $fields the fields to search, never empty
     *
     * @return list<SearchClause> AND-ed; empty when the term has nothing to search for
     */
    public function clauses(string $term, array $fields): array;
}
