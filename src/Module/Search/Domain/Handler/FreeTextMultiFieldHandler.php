<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Handler;

use App\Module\Search\Contract\Dto\SearchClause;
use App\Module\Search\Contract\Dto\SearchMatch;
use App\Module\Search\Contract\SearchHandlerInterface;
use App\Module\Search\Domain\SearchTokenizer;

/**
 * Every word must occur in at least one field, in any order: "John London" finds
 * first_name = John and city = London, and so does "London John".
 */
final class FreeTextMultiFieldHandler implements SearchHandlerInterface
{
    public const string NAME = 'free_text_multi_field';

    public function name(): string
    {
        return self::NAME;
    }

    public function clauses(string $term, array $fields): array
    {
        return array_map(
            static fn (string $token): SearchClause => new SearchClause($fields, SearchMatch::Contains, $token),
            SearchTokenizer::tokenize($term),
        );
    }
}
