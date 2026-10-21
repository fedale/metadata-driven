<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Repository;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Domain\Exception\UniqueValueConflict;

/**
 * Storage of records in the physical object tables.
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
     * Ids of the live records of $object whose relation $field points at $targetId, newest first.
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
     * Clears $field on the live records of $object pointing at $targetId.
     */
    public function clearReferences(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId, \DateTimeImmutable $now): void;
}
