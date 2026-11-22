<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Validation\Contract\ReferenceCheckerInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalReferenceChecker implements ReferenceCheckerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordTable $table,
    ) {
    }

    public function exists(ObjectDefinitionDto $target, string $id): bool
    {
        // Not a key of the target: no record has it (and the query would fail on it).
        $id = $target->keyType->normalize($id);
        if (null === $id) {
            return false;
        }
        $sql = \sprintf(
            'SELECT 1 FROM %s WHERE %s = ? AND %s IS NULL',
            $this->table->table($target),
            $this->table->column('id'),
            $this->table->column('deleted_at'),
        );

        return false !== $this->connection->fetchOne($sql, [$id], [$this->table->idType($target)]);
    }

    public function missing(ObjectDefinitionDto $target, array $ids): array
    {
        $valid = array_values(array_filter($ids, $target->keyType->isValid(...)));
        if ([] === $valid) {
            return $ids;
        }
        $sql = \sprintf(
            'SELECT %s FROM %s WHERE %s IN (?) AND %s IS NULL',
            $this->table->column('id'),
            $this->table->table($target),
            $this->table->column('id'),
            $this->table->column('deleted_at'),
        );
        $found = array_map(KeyType::fromDatabase(...), $this->connection->fetchFirstColumn($sql, [array_map(fn (string $id): string => (string) $target->keyType->normalize($id), $valid)], [ArrayParameterType::STRING]));

        return array_values(array_filter($ids, static fn (string $id): bool => !\in_array($target->keyType->normalize($id), $found, true)));
    }

    public function pointsAt(ObjectDefinitionDto $target, string $id, string $field, string $parentId): bool
    {
        $id = $target->keyType->normalize($id);
        $parentType = $this->table->targetKeyType($target->field($field)) ?? KeyType::Uuid;
        $parentId = $parentType->normalize($parentId);
        if (null === $id || null === $parentId) {
            return false;
        }
        $sql = \sprintf(
            'SELECT 1 FROM %s WHERE %s = ? AND %s = ? AND %s IS NULL',
            $this->table->table($target),
            $this->table->column('id'),
            $this->table->column($target->field($field)->columnName),
            $this->table->column('deleted_at'),
        );

        return false !== $this->connection->fetchOne($sql, [$id, $parentId], [$this->table->idType($target), $parentType->dbalType()]);
    }
}
