<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Record\Contract\Dto\RecordLabel;

/**
 * Finds records by their label, to pick one in a relation field or a filter.
 */
interface RecordLookupInterface
{
    /**
     * Live records whose label contains the term (all of them when it is empty), by label.
     *
     * @return list<RecordLabel>
     *
     * @throws ObjectDefinitionNotFound
     */
    public function search(string $objectName, string $term, int $limit = 20): array;

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> id => label, for the live records among the ids
     *
     * @throws ObjectDefinitionNotFound
     */
    public function labels(string $objectName, array $ids): array;
}
