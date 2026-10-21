<?php

declare(strict_types=1);

namespace App\Module\Search\Contract;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Search\Contract\Dto\SearchClause;

/**
 * The search of an object, as configured in its metadata (or the defaults).
 */
interface SearchConfigurationInterface
{
    /**
     * The fields searched by default: the configured ones, or every text-like field.
     *
     * @return list<string>
     */
    public function fieldsFor(ObjectDefinitionDto $object): array;

    /**
     * @param list<string>|null $fields the fields to search; null for fieldsFor()
     *
     * @return list<SearchClause>
     */
    public function clauses(ObjectDefinitionDto $object, string $term, ?array $fields = null): array;
}
