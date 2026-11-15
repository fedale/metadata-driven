<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Record\Contract\Dto\RecordFilter;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Dto\RecordSearchResult;
use App\Module\Record\Contract\Dto\ReferencingGroup;
use App\Module\Record\Contract\Exception\InvalidRecordSearch;

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
     * Every live record, oldest first, read in batches: for exports of any size.
     *
     * @return iterable<RecordOutput>
     *
     * @throws ObjectDefinitionNotFound
     */
    public function each(string $objectName): iterable;

    /**
     * The live record whose field holds the value, compared as text; with a unique field, the
     * one there is.
     *
     * @throws ObjectDefinitionNotFound
     *
     * @phpstan-impure the result changes after every write
     */
    public function findBy(string $objectName, string $fieldName, string $value): ?RecordOutput;

    /**
     * The live records every filter holds for, sorted on a field (or the oldest first), the
     * first $limit of them, and how many there are in all. Text compares as written, contains and
     * the like ignore case; a field searched on holds a column (no many-to-many, file or
     * password).
     *
     * @param list<RecordFilter> $filters
     *
     * @throws ObjectDefinitionNotFound
     * @throws InvalidRecordSearch      before any query
     *
     * @phpstan-impure the result changes after every write
     */
    public function search(string $objectName, array $filters, ?string $sortField = null, bool $descending = false, int $limit = 50): RecordSearchResult;

    /**
     * The records of an object whose relation field points at a record, oldest first.
     *
     * @return list<RecordOutput>
     *
     * @throws ObjectDefinitionNotFound
     */
    public function findReferencing(string $objectName, string $fieldName, string $targetId): array;

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
