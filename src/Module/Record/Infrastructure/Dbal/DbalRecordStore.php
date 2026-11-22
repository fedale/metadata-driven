<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordFilter;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Dto\RecordSearchResult;
use App\Module\Record\Contract\Exception\InvalidRecordSearch;
use App\Module\Record\Domain\Exception\UniqueValueConflict;
use App\Module\Record\Domain\Repository\RecordStore;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

final class DbalRecordStore implements RecordStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordTable $table,
    ) {
    }

    public function insert(ObjectDefinitionDto $object, ?string $id, array $values, \DateTimeImmutable $now): string
    {
        // No id: the database makes it (an integer key).
        $columns = null === $id ? [] : [$this->table->quote('id')];
        $params = null === $id ? [] : [$id];
        $types = null === $id ? [] : [$this->table->idType($object)];
        array_push($columns, $this->table->quote('created_at'), $this->table->quote('updated_at'));
        array_push($params, $now, $now);
        array_push($types, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE);
        foreach ($values as $name => $value) {
            $field = $object->field($name);
            $columns[] = $this->table->quote($field->columnName);
            $params[] = $value;
            $types[] = $this->table->typeOf($field);
        }

        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
            $this->table->table($object),
            implode(', ', $columns),
            implode(', ', array_fill(0, \count($columns), '?')),
            $this->table->quote('id'),
        );
        try {
            $made = $this->connection->transactional(fn (): mixed => $this->connection->fetchOne($sql, $params, $types));
        } catch (UniqueConstraintViolationException $e) {
            throw new UniqueValueConflict($e->getMessage(), previous: $e);
        }

        return KeyType::fromDatabase($made) ?? throw new \LogicException(\sprintf('The record just written in "%s" has no id.', $object->tableName));
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

        return $this->execute($sql, [...$params, $id], [...$types, $this->table->idType($object)]) > 0;
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

        return $this->connection->executeStatement($sql, [$now, $now, $id], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, $this->table->idType($object)]) > 0;
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
        $row = $this->connection->fetchAssociative($sql, [$id], [$this->table->idType($object)]);

        return false === $row ? null : $this->table->hydrate($object, $row, $this->linksOf($object, [$id])[$id] ?? []);
    }

    public function lockedValue(ObjectDefinitionDto $object, string $id, FieldDefinitionDto $field): mixed
    {
        $value = $this->connection->fetchOne(
            \sprintf('SELECT %s FROM %s WHERE %s = ? AND %s IS NULL FOR UPDATE', $this->table->quote($field->columnName), $this->table->table($object), $this->table->quote('id'), $this->table->quote('deleted_at')),
            [$id],
            [$this->table->idType($object)],
        );

        return false === $value ? null : $value;
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
                $id = KeyType::fromDatabase($row['id']);
                if (\is_string($row['value']) && null !== $id) {
                    $ids[$row['value']] = $id;
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
                : $this->connection->fetchAllAssociative($sql, [$after[0], $after[1], $batch], [ParameterType::STRING, $this->table->idType($object), ParameterType::INTEGER]);
            $links = $this->linksOf($object, self::ids($rows));
            foreach ($rows as $row) {
                yield $this->table->hydrate($object, $row, $links[KeyType::fromDatabase($row['id'] ?? null) ?? ''] ?? []);
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
        $links = $this->linksOf($object, self::ids($rows));

        return array_map(
            fn (array $row): RecordOutput => $this->table->hydrate($object, $row, $links[KeyType::fromDatabase($row['id'] ?? null) ?? ''] ?? []),
            $rows,
        );
    }

    public function search(ObjectDefinitionDto $object, array $filters, ?string $sortField, bool $descending, int $limit): RecordSearchResult
    {
        $where = [$this->table->quote('deleted_at').' IS NULL'];
        $params = [];
        $types = [];
        foreach ($filters as $i => $filter) {
            $where[] = $this->searchClause($object, $filter, 'f'.$i, $params, $types);
        }
        $from = \sprintf('FROM %s WHERE %s', $this->table->table($object), implode(' AND ', $where));
        $order = null === $sortField ? '' : $this->searchColumn($object, $sortField)[0].($descending ? ' DESC' : ' ASC').', ';

        $total = $this->connection->fetchOne('SELECT COUNT(*) '.$from, $params, $types);
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT %s %s ORDER BY %s%s, %s LIMIT %d',
            $this->table->selectList($object),
            $from,
            $order,
            $this->table->quote('created_at'),
            $this->table->quote('id'),
            max(1, $limit),
        ), $params, $types);
        $links = $this->linksOf($object, self::ids($rows));

        return new RecordSearchResult(
            array_map(fn (array $row): RecordOutput => $this->table->hydrate($object, $row, $links[KeyType::fromDatabase($row['id'] ?? null) ?? ''] ?? []), $rows),
            is_numeric($total) ? (int) $total : 0,
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
                [$links->recordType],
            );

            return;
        }

        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE %s = ? AND %s NOT IN (?)', $links->table, $links->recordColumn, $links->targetColumn),
            [$recordId, $targetIds],
            [$links->recordType, ArrayParameterType::STRING],
        );
        // The links already there keep their date.
        $params = [];
        $types = [];
        foreach ($targetIds as $targetId) {
            array_push($params, $recordId, $targetId, $now);
            array_push($types, $links->recordType, $links->targetType, Types::DATETIME_IMMUTABLE);
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
            [$this->table->idType($object)],
        );
        if ([] === $fileIds) {
            return;
        }

        $params = [];
        $types = [];
        foreach ($fileIds as $position => $fileId) {
            array_push($params, $recordId, $fileId, $position);
            array_push($types, $this->table->idType($object), Types::GUID, Types::INTEGER);
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

        return array_values(array_filter(array_map(KeyType::fromDatabase(...), $this->connection->fetchFirstColumn($sql, [$targetId], [$this->targetType($field)])), is_string(...)));
    }

    public function countReferencing(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $targetId): int
    {
        $sql = \sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s AND %s IS NULL',
            $this->table->table($object),
            $this->pointsAt($object, $field),
            $this->table->column('deleted_at'),
        );
        $count = $this->connection->fetchOne($sql, [$targetId], [$this->targetType($field)]);

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
                [Types::DATETIME_IMMUTABLE, $links->targetType],
            );
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s = ? AND %s IN (%s)', $links->table, $links->targetColumn, $links->recordColumn, $live),
                [$targetId],
                [$links->targetType],
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
        $this->connection->executeStatement($sql, [$now, $targetId], [Types::DATETIME_IMMUTABLE, $this->targetType($field)]);
    }

    /**
     * The DBAL type binding a key of the object a relation or many-to-many field points at.
     */
    private function targetType(FieldDefinitionDto $field): string
    {
        return ($this->table->targetKeyType($field) ?? KeyType::Uuid)->dbalType();
    }

    /**
     * The ids of some rows, as text.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_values(array_filter(array_map(static fn (array $row): ?string => KeyType::fromDatabase($row['id'] ?? null), $rows), is_string(...)));
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
                    $recordId = KeyType::fromDatabase($recordId);
                    if (null !== $recordId && \is_string($fileId)) {
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
                $recordId = KeyType::fromDatabase($recordId);
                $targetId = KeyType::fromDatabase($targetId);
                if (null !== $recordId && null !== $targetId) {
                    $result[$recordId][$name][] = $targetId;
                }
            }
        }

        return $result;
    }

    /**
     * The SQL of a filter, its values checked and bound as parameters named after $param.
     *
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     *
     * @param-out array<string, mixed>                $params
     * @param-out array<string, ParameterType|string> $types
     *
     * @throws InvalidRecordSearch
     */
    private function searchClause(ObjectDefinitionDto $object, RecordFilter $filter, string $param, array &$params, array &$types): string
    {
        [$column, $cast, $text] = $this->searchColumn($object, $filter->field);
        $bind = function (mixed $value, string $name) use ($filter, $cast, &$params, &$types): string {
            $value = $this->searchValue($filter->field, $value, $cast);
            $params[$name] = $value;
            $types[$name] = \is_bool($value) ? ParameterType::BOOLEAN : ParameterType::STRING;

            return 'TEXT' === $cast ? ':'.$name : \sprintf('CAST(:%s AS %s)', $name, $cast);
        };
        $value = $filter->value;

        return match ($filter->operator) {
            'eq' => null === $value ? $column.' IS NULL' : \sprintf('%s = %s', $column, $bind($value, $param)),
            'neq' => null === $value ? $column.' IS NOT NULL' : \sprintf('(%1$s <> %2$s OR %1$s IS NULL)', $column, $bind($value, $param)),
            'lt', 'lte', 'gt', 'gte' => \sprintf('%s %s %s', $column, ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$filter->operator], $bind($value, $param)),
            'contains', 'not_contains', 'starts_with', 'ends_with' => $this->searchLike($text, $filter, $param, $params, $types),
            'in' => \is_array($value)
                ? ([] === $value ? 'FALSE' : \sprintf('%s IN (%s)', $column, implode(', ', array_map(static fn (mixed $item, int $i): string => $bind($item, $param.'_'.$i), array_values($value), array_keys(array_values($value))))))
                : throw new InvalidRecordSearch(\sprintf('"in" on "%s" takes a list of values.', $filter->field)),
            'between' => \is_array($value) && 2 === \count($value)
                ? \sprintf('%s BETWEEN %s AND %s', $column, $bind(array_values($value)[0], $param.'_low'), $bind(array_values($value)[1], $param.'_high'))
                : throw new InvalidRecordSearch(\sprintf('"between" on "%s" takes two values: the lowest and the highest.', $filter->field)),
            'empty' => 'TEXT' === $cast ? \sprintf("(%1\$s IS NULL OR %1\$s = '')", $column) : $column.' IS NULL',
            'not_empty' => 'TEXT' === $cast ? \sprintf("(%1\$s IS NOT NULL AND %1\$s <> '')", $column) : $column.' IS NOT NULL',
            default => throw new InvalidRecordSearch(\sprintf('"%s" is no operator: one of %s.', $filter->operator, implode(', ', RecordFilter::OPERATORS))),
        };
    }

    /**
     * contains, starts_with and the like: the column as text, case ignored.
     *
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     *
     * @param-out array<string, mixed>                $params
     * @param-out array<string, ParameterType|string> $types
     */
    private function searchLike(string $text, RecordFilter $filter, string $param, array &$params, array &$types): string
    {
        if (!\is_scalar($filter->value)) {
            throw new InvalidRecordSearch(\sprintf('"%s" on "%s" takes a text.', $filter->operator, $filter->field));
        }
        [$before, $after] = match ($filter->operator) {
            'starts_with' => ['', '%'],
            'ends_with' => ['%', ''],
            default => ['%', '%'],
        };
        $params[$param] = $before.RecordTable::escapeLike(mb_strtolower((string) $filter->value)).$after;
        $types[$param] = ParameterType::STRING;

        return 'not_contains' === $filter->operator
            ? \sprintf('(LOWER(%1$s) NOT LIKE :%2$s OR %1$s IS NULL)', $text, $param)
            : \sprintf('LOWER(%s) LIKE :%s', $text, $param);
    }

    /**
     * A field a search may test or sort on: its column, the SQL type its values are cast to
     * ("TEXT": none), and the column as text.
     *
     * @return array{string, string, string}
     *
     * @throws InvalidRecordSearch
     */
    private function searchColumn(ObjectDefinitionDto $object, string $name): array
    {
        if ('id' === $name) {
            $id = $this->table->quote('id');

            return [$id, KeyType::Uuid === $object->keyType ? 'UUID' : 'NUMERIC', 'CAST('.$id.' AS TEXT)'];
        }
        $field = $object->fields[$name] ?? throw new InvalidRecordSearch(\sprintf('Object "%s" has no field "%s".', $object->name, $name));
        if (!$field->storesColumn() || null !== $field->fileList() || 'file' === $field->type || $this->table->isWriteOnly($field)) {
            throw new InvalidRecordSearch(\sprintf('Field "%s" of "%s" cannot be searched on: a many-to-many, file or password field.', $name, $object->name));
        }
        $cast = match ($this->table->typeOf($field)) {
            Types::INTEGER, Types::BIGINT, Types::DECIMAL => 'NUMERIC',
            Types::BOOLEAN => 'BOOLEAN',
            Types::DATE_IMMUTABLE => 'DATE',
            Types::DATETIME_IMMUTABLE => 'TIMESTAMPTZ',
            Types::GUID => 'UUID',
            default => 'TEXT',
        };

        return [$this->table->column($field->columnName), $cast, $this->table->asText($field)];
    }

    /**
     * A value checked against the type it is cast to: a wrong one would fail the query, and with
     * it the transaction it runs in.
     *
     * @throws InvalidRecordSearch
     */
    private function searchValue(string $field, mixed $value, string $cast): string|bool
    {
        if ($value instanceof \DateTimeInterface) {
            $value = 'DATE' === $cast ? $value->format('Y-m-d') : $value->format(\DateTimeInterface::ATOM);
        }
        $wrong = static fn (string $what): InvalidRecordSearch => new InvalidRecordSearch(\sprintf('%s is no %s, for "%s".', json_encode($value), $what, $field));
        if (!\is_scalar($value)) {
            throw $wrong('single value');
        }
        $text = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return match ($cast) {
            'NUMERIC' => is_numeric($text) ? $text : throw $wrong('number'),
            'BOOLEAN' => filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? throw $wrong('boolean'),
            'DATE' => false !== ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text)) && $date->format('Y-m-d') === $text ? $text : throw $wrong('date (2026-12-31)'),
            'TIMESTAMPTZ' => self::timestamp($text) ?? throw $wrong('date and time'),
            'UUID' => Uuid::isValid($text) ? $text : throw $wrong('id'),
            default => $text,
        };
    }

    private static function timestamp(string $text): ?string
    {
        try {
            return '' === trim($text) ? null : (new \DateTimeImmutable($text))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
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
