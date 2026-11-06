<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Domain\Exception\UniqueValueConflict;
use App\Module\Record\Domain\Repository\RecordStore;
use Doctrine\DBAL\ArrayParameterType;
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

        return false === $row ? null : $this->table->hydrate($object, $row, $this->linksOf($object, [$id])[$id] ?? []);
    }

    public function idsByValues(ObjectDefinitionDto $object, FieldDefinitionDto $field, array $values): array
    {
        $ids = [];
        // In chunks: PostgreSQL takes at most 65535 parameters in one statement.
        foreach (array_chunk(array_values(array_unique($values)), 1000) as $chunk) {
            $sql = \sprintf(
                'SELECT %1$s AS value, %2$s AS id FROM %3$s WHERE %1$s IN (?) AND %4$s IS NULL',
                $this->table->asText($field),
                $this->table->quote('id'),
                $this->table->table($object),
                $this->table->quote('deleted_at'),
            );
            foreach ($this->connection->fetchAllAssociative($sql, [$chunk], [ArrayParameterType::STRING]) as $row) {
                if (\is_string($row['value']) && \is_string($row['id'])) {
                    $ids[$row['value']] = $row['id'];
                }
            }
        }

        return $ids;
    }

    public function each(ObjectDefinitionDto $object, int $batch = 500): iterable
    {
        // Keyset paging on (created_at, id): records written meanwhile neither shift nor repeat a page.
        $after = null;
        do {
            $sql = \sprintf(
                'SELECT %1$s FROM %2$s WHERE %3$s IS NULL%4$s ORDER BY %5$s, %6$s LIMIT ?',
                $this->table->selectList($object),
                $this->table->table($object),
                $this->table->quote('deleted_at'),
                null === $after ? '' : \sprintf(' AND (%s, %s) > (?, ?)', $this->table->quote('created_at'), $this->table->quote('id')),
                $this->table->quote('created_at'),
                $this->table->quote('id'),
            );
            $rows = null === $after
                ? $this->connection->fetchAllAssociative($sql, [$batch], [ParameterType::INTEGER])
                : $this->connection->fetchAllAssociative($sql, [$after[0], $after[1], $batch], [ParameterType::STRING, Types::GUID, ParameterType::INTEGER]);
            $links = $this->linksOf($object, array_values(array_filter(array_column($rows, 'id'), is_string(...))));
            foreach ($rows as $row) {
                yield $this->table->hydrate($object, $row, \is_string($row['id'] ?? null) ? ($links[$row['id']] ?? []) : []);
            }
            $last = end($rows);
            $after = false === $last ? null : [$last['created_at'], $last['id']];
        } while (\count($rows) === $batch && null !== $after);
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
        $links = $this->linksOf($object, array_values(array_filter(array_column($rows, 'id'), is_string(...))));

        return array_map(
            fn (array $row): RecordOutput => $this->table->hydrate($object, $row, \is_string($row['id'] ?? null) ? ($links[$row['id']] ?? []) : []),
            $rows,
        );
    }

    public function replaceLinks(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $recordId, array $targetIds, \DateTimeImmutable $now): void
    {
        $links = $this->table->links($object, $field);
        if ($links->isJunction) {
            throw new \LogicException(\sprintf('Field "%s.%s" goes through a junction object: its links are written as records of that object.', $object->name, $field->name));
        }
        if ([] === $targetIds) {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s = ?', $links->table, $links->recordColumn),
                [$recordId],
                [Types::GUID],
            );

            return;
        }

        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE %s = ? AND %s NOT IN (?)', $links->table, $links->recordColumn, $links->targetColumn),
            [$recordId, $targetIds],
            [Types::GUID, ArrayParameterType::STRING],
        );
        // The links already there keep their date.
        $params = [];
        $types = [];
        foreach ($targetIds as $targetId) {
            array_push($params, $recordId, $targetId, $now);
            array_push($types, Types::GUID, Types::GUID, Types::DATETIME_IMMUTABLE);
        }
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (%s, %s, %s) VALUES %s ON CONFLICT DO NOTHING',
                $links->table,
                $links->recordColumn,
                $links->targetColumn,
                $this->table->quote('created_at'),
                implode(', ', array_fill(0, \count($targetIds), '(?, ?, ?)')),
            ),
            $params,
            $types,
        );
    }

    public function replaceFiles(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $recordId, array $fileIds): void
    {
        $table = $this->table->fileListTable($object, $field);
        // Rewritten whole: a move changes the positions of every file between the two places.
        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE %s = ?', $table, $this->table->quote('record_id')),
            [$recordId],
            [Types::GUID],
        );
        if ([] === $fileIds) {
            return;
        }

        $params = [];
        $types = [];
        foreach ($fileIds as $position => $fileId) {
            array_push($params, $recordId, $fileId, $position);
            array_push($types, Types::GUID, Types::GUID, Types::INTEGER);
        }
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (%s, %s, %s) VALUES %s',
                $table,
                $this->table->quote('record_id'),
                $this->table->quote('target_id'),
                $this->table->quote('position'),
                implode(', ', array_fill(0, \count($fileIds), '(?, ?, ?)')),
            ),
            $params,
            $types,
        );
    }

    public function referencingIds(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId, ?int $limit = null): array
    {
        $sql = \sprintf(
            'SELECT %s FROM %s WHERE %s AND %s IS NULL ORDER BY %s DESC, %s DESC',
            $this->table->column('id'),
            $this->table->table($object),
            $this->pointsAt($object, $field),
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
            'SELECT COUNT(*) FROM %s WHERE %s AND %s IS NULL',
            $this->table->table($object),
            $this->pointsAt($object, $field),
            $this->table->column('deleted_at'),
        );
        $count = $this->connection->fetchOne($sql, [$targetId], [Types::GUID]);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function clearReferences(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId, \DateTimeImmutable $now): void
    {
        if (!$field->storesColumn()) {
            $live = \sprintf('SELECT %s FROM %s WHERE %s IS NULL', $this->table->column('id'), $this->table->table($object), $this->table->column('deleted_at'));
            $links = $this->table->links($object, $field);
            if ($links->isJunction) {
                throw new \LogicException(\sprintf('Field "%s.%s" goes through a junction object: the relation fields of that object carry the delete rules.', $object->name, $field->name));
            }
            $this->connection->executeStatement(
                \sprintf(
                    'UPDATE %s SET %s = ? WHERE %s IS NULL AND %s IN (SELECT %s FROM %s WHERE %s = ?)',
                    $this->table->table($object),
                    $this->table->column('updated_at'),
                    $this->table->column('deleted_at'),
                    $this->table->column('id'),
                    $links->recordColumn,
                    $links->table,
                    $links->targetColumn,
                ),
                [$now, $targetId],
                [Types::DATETIME_IMMUTABLE, Types::GUID],
            );
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s = ? AND %s IN (%s)', $links->table, $links->targetColumn, $links->recordColumn, $live),
                [$targetId],
                [Types::GUID],
            );

            return;
        }

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
     * The condition "the record points at the bound target id": its relation column, or one of
     * its links.
     */
    private function pointsAt(ObjectDefinitionDto $object, FieldDefinitionDto $field): string
    {
        if ($field->storesColumn()) {
            return $this->table->column($field->columnName).' = ?';
        }
        $links = $this->table->links($object, $field);

        return \sprintf(
            '%s IN (SELECT %s FROM %s WHERE %s = ?%s)',
            $this->table->column('id'),
            $links->recordColumn,
            $links->table,
            $links->targetColumn,
            $links->andLive(),
        );
    }

    /**
     * The live linked ids of every many-to-many field of some records, oldest link first, and
     * the file ids of every file list, in their order. The delete rules remove the links to a
     * deleted record (or refuse the deletion); one left over all the same is not shown. A
     * listed file always exists: its foreign key restricts deleting it.
     *
     * @param list<string> $recordIds
     *
     * @return array<string, array<string, list<string>>> record id => field name => linked ids
     */
    private function linksOf(ObjectDefinitionDto $object, array $recordIds): array
    {
        $result = [];
        if ([] === $recordIds) {
            return $result;
        }
        foreach ($object->fields as $name => $field) {
            if (null !== $field->fileList()) {
                $rows = $this->connection->fetchAllNumeric(
                    \sprintf(
                        'SELECT %1$s, %2$s FROM %3$s WHERE %1$s IN (?) ORDER BY %1$s, %4$s',
                        $this->table->quote('record_id'),
                        $this->table->quote('target_id'),
                        $this->table->fileListTable($object, $field),
                        $this->table->quote('position'),
                    ),
                    [$recordIds],
                    [ArrayParameterType::STRING],
                );
                foreach ($rows as [$recordId, $fileId]) {
                    if (\is_string($recordId) && \is_string($fileId)) {
                        $result[$recordId][$name][] = $fileId;
                    }
                }
                continue;
            }
            if (null === $field->manyToMany()) {
                continue;
            }
            $links = $this->table->links($object, $field);
            $rows = $this->connection->fetchAllNumeric(
                \sprintf(
                    'SELECT l.%1$s, l.%2$s FROM %3$s l JOIN %4$s t ON t.%5$s = l.%2$s AND t.%6$s IS NULL WHERE l.%1$s IN (?)%8$s ORDER BY l.%7$s, l.%2$s',
                    $links->recordColumn,
                    $links->targetColumn,
                    $links->table,
                    $links->targetTable,
                    $this->table->quote('id'),
                    $this->table->quote('deleted_at'),
                    $this->table->quote('created_at'),
                    $links->andLive('l'),
                ),
                [$recordIds],
                [ArrayParameterType::STRING],
            );
            foreach ($rows as [$recordId, $targetId]) {
                if (\is_string($recordId) && \is_string($targetId)) {
                    $result[$recordId][$name][] = $targetId;
                }
            }
        }

        return $result;
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
