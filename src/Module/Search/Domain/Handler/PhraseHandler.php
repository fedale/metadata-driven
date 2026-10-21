<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Handler;

use App\Module\Search\Contract\Dto\SearchClause;
use App\Module\Search\Contract\Dto\SearchMatch;
use App\Module\Search\Contract\SearchHandlerInterface;

/**
 * The whole term, as typed, must occur in one field: for codes and identifiers
 * where word order matters.
 */
final class PhraseHandler implements SearchHandlerInterface
{
    public const string NAME = 'phrase';

    public function name(): string
    {
        return self::NAME;
    }

    public function clauses(string $term, array $fields): array
    {
        $phrase = (string) preg_replace('/\s+/u', ' ', trim(mb_strtolower($term)));

        return '' === $phrase ? [] : [new SearchClause($fields, SearchMatch::Contains, $phrase)];
    }
}
