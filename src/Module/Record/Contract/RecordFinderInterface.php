<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Dto\ReferencingGroup;

/**
 * Read side of records; soft deleted records are never returned.
 */
interface RecordFinderInterface
{
    /**
     * @throws ObjectDefinitionNotFound
     *
     * @phpstan-impure the result changes after every write
     */
    public function find(string $objectName, string $id): ?RecordOutput;

    /**
     * Most recently created records first. Filtering, sorting and paging come with the grid view.
     *
     * @return list<RecordOutput>
     *
     * @throws ObjectDefinitionNotFound
     */
    public function latest(string $objectName, int $limit = 50): array;

    /**
     * The inverse side of every relation pointing at a record: one group per relation
     * field, each with its first $limit records.
     *
     * @return list<ReferencingGroup>
     *
     * @throws ObjectDefinitionNotFound
     */
    public function referencing(string $objectName, string $id, int $limit = 20): array;
}
