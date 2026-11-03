<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Repository;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Domain\Exception\UniqueValueConflict;

/**
 * Storage of records in the physical object tables, and of the links of their many-to-many
 * fields in the link tables.
 *
 * insert() and update() write the columns only; the links are written by replaceLinks(). A
 * record read back carries its links (the live linked records only).
 */
interface RecordStore
{
    /**
     * @param array<string, mixed> $values denormalized values keyed by field name
     *
     * @throws UniqueValueConflict
     */
    public function insert(ObjectDefinitionDto $object, string $id, array $values, \DateTimeImmutable $now): void;

    /**
     * @param array<string, mixed> $values denormalized values of the changed fields
     *
     * @return bool false when no live record has this id
     *
     * @throws UniqueValueConflict
     */
    public function update(ObjectDefinitionDto $object, string $id, array $values, \DateTimeImmutable $now): bool;

    /**
     * @return bool false when no live record has this id
     */
    public function softDelete(ObjectDefinitionDto $object, string $id, \DateTimeImmutable $now): bool;

    /**
     * @phpstan-impure the result changes after every write
     */
    public function find(ObjectDefinitionDto $object, string $id): ?RecordOutput;

    /**
     * @return list<RecordOutput>
     *
     * @phpstan-impure
     */
    public function latest(ObjectDefinitionDto $object, int $limit): array;

    /**
     * Makes the many-to-many $field of a record link exactly to $targetIds: the links missing are
     * added, the others removed; the links kept keep their date.
     *
     * @param list<string> $targetIds
     */
    public function replaceLinks(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $recordId, array $targetIds, \DateTimeImmutable $now): void;

    /**
     * Ids of the live records of $object whose relation $field points at $targetId (or whose
     * many-to-many $field links to it), newest first.
     *
     * @return list<string>
     *
     * @phpstan-impure
     */
    public function referencingIds(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId, ?int $limit = null): array;

    /**
     * @phpstan-impure
     */
    public function countReferencing(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId): int;

    /**
     * Clears $field on the live records of $object pointing at $targetId; for a many-to-many
     * $field, removes their links to it. Either way the records count as updated.
     */
    public function clearReferences(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId, \DateTimeImmutable $now): void;
}
