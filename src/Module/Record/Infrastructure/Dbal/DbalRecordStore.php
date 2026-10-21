<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Domain\Exception\UniqueValueConflict;
use App\Module\Record\Domain\Repository\RecordStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

final class DbalRecordStore implements RecordStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordTable $table,
    ) {
    }

    public function insert(ObjectDefinitionDto $object, string $id, array $values, \DateTimeImmutable $now): void
    {
        $columns = [$this->table->quote('id'), $this->table->quote('created_at'), $this->table->quote('updated_at')];
        $params = [$id, $now, $now];
        $types = [Types::GUID, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE];
        foreach ($values as $name => $value) {
            $field = $object->field($name);
            $columns[] = $this->table->quote($field->columnName);
            $params[] = $value;
            $types[] = $this->table->typeOf($field);
        }

        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table->table($object),
            implode(', ', $columns),
            implode(', ', array_fill(0, \count($columns), '?')),
        );
        $this->execute($sql, $params, $types);
    }

    public function update(ObjectDefinitionDto $object, string $id, array $values, \DateTimeImmutable $now): bool
    {
        $assignments = [$this->table->quote('updated_at').' = ?'];
        $params = [$now];
        $types = [Types::DATETIME_IMMUTABLE];
        foreach ($values as $name => $value) {
            $field = $object->field($name);
            $assignments[] = $this->table->quote($field->columnName).' = ?';
            $params[] = $value;
            $types[] = $this->table->typeOf($field);
        }

        $sql = \sprintf(
            'UPDATE %s SET %s WHERE %s = ? AND %s IS NULL',
            $this->table->table($object),
            implode(', ', $assignments),
            $this->table->quote('id'),
            $this->table->quote('deleted_at'),
        );

        return $this->execute($sql, [...$params, $id], [...$types, Types::GUID]) > 0;
    }

    public function softDelete(ObjectDefinitionDto $object, string $id, \DateTimeImmutable $now): bool
    {
        $sql = \sprintf(
            'UPDATE %1$s SET %2$s = ?, %3$s = ? WHERE %4$s = ? AND %2$s IS NULL',
            $this->table->table($object),
            $this->table->quote('deleted_at'),
            $this->table->quote('updated_at'),
            $this->table->quote('id'),
        );

        return $this->connection->executeStatement($sql, [$now, $now, $id], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID]) > 0;
    }

    public function find(ObjectDefinitionDto $object, string $id): ?RecordOutput
    {
        $sql = \sprintf(
            'SELECT %s FROM %s WHERE %s = ? AND %s IS NULL',
            $this->table->selectList($object),
            $this->table->table($object),
            $this->table->quote('id'),
            $this->table->quote('deleted_at'),
        );
        $row = $this->connection->fetchAssociative($sql, [$id], [Types::GUID]);

        return false === $row ? null : $this->table->hydrate($object, $row);
    }

    public function latest(ObjectDefinitionDto $object, int $limit): array
    {
        $sql = \sprintf(
            'SELECT %s FROM %s WHERE %s IS NULL ORDER BY %s DESC, %s DESC LIMIT ?',
            $this->table->selectList($object),
            $this->table->table($object),
            $this->table->quote('deleted_at'),
            $this->table->quote('created_at'),
            $this->table->quote('id'),
        );
        $rows = $this->connection->fetchAllAssociative($sql, [$limit], [ParameterType::INTEGER]);

        return array_map(fn (array $row): RecordOutput => $this->table->hydrate($object, $row), $rows);
    }

    public function referencingIds(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId, ?int $limit = null): array
    {
        $sql = \sprintf(
            'SELECT %s FROM %s WHERE %s = ? AND %s IS NULL ORDER BY %s DESC, %s DESC',
            $this->table->column('id'),
            $this->table->table($object),
            $this->table->column($field->columnName),
            $this->table->column('deleted_at'),
            $this->table->column('created_at'),
            $this->table->column('id'),
        );
        if (null !== $limit) {
            $sql .= ' LIMIT '.max(0, $limit);
        }

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn($sql, [$targetId], [Types::GUID]);

        return $ids;
    }

    public function countReferencing(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId): int
    {
        $sql = \sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s = ? AND %s IS NULL',
            $this->table->table($object),
            $this->table->column($field->columnName),
            $this->table->column('deleted_at'),
        );
        $count = $this->connection->fetchOne($sql, [$targetId], [Types::GUID]);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function clearReferences(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId, \DateTimeImmutable $now): void
    {
        $sql = \sprintf(
            'UPDATE %1$s SET %2$s = NULL, %3$s = ? WHERE %2$s = ? AND %4$s IS NULL',
            $this->table->table($object),
            $this->table->column($field->columnName),
            $this->table->column('updated_at'),
            $this->table->column('deleted_at'),
        );
        $this->connection->executeStatement($sql, [$now, $targetId], [Types::DATETIME_IMMUTABLE, Types::GUID]);
    }

    /**
     * Runs the write in a (nested) transaction, so that a constraint violation only
     * rolls back this statement even when an outer transaction is open.
     *
     * @param list<mixed>  $params
     * @param list<string> $types
     *
     * @return int|string number of affected rows
     */
    private function execute(string $sql, array $params, array $types): int|string
    {
        try {
            return $this->connection->transactional(fn () => $this->connection->executeStatement($sql, $params, $types));
        } catch (UniqueConstraintViolationException $e) {
            throw new UniqueValueConflict($e->getMessage(), previous: $e);
        }
    }
}
