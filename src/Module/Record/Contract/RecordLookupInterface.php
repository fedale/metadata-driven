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
     * @param array<string, string> $filters relation field => record id: only the records pointing
     *                                       at it (the municipalities of a province)
     *
     * @return list<RecordLabel>
     *
     * @throws ObjectDefinitionNotFound
     * @throws \InvalidArgumentException a filter names no relation field of the object
     */
    public function search(string $objectName, string $term, int $limit = 20, array $filters = []): array;

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> id => label, for the live records among the ids
     *
     * @throws ObjectDefinitionNotFound
     */
    public function labels(string $objectName, array $ids): array;
}
