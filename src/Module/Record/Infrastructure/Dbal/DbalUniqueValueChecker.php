<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Validation\Contract\UniqueValueCheckerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final class DbalUniqueValueChecker implements UniqueValueCheckerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordTable $table,
    ) {
    }

    public function isTaken(ObjectDefinitionDto $object, FieldDefinitionDto $field, mixed $value, ?string $exceptRecordId = null): bool
    {
        // Soft deleted records are included on purpose: the unique index still covers them.
        $sql = \sprintf(
            'SELECT 1 FROM %s WHERE %s = ?',
            $this->table->table($object),
            $this->table->quote($field->columnName),
        );
        $params = [$value];
        $types = [$this->table->typeOf($field)];
        if (null !== $exceptRecordId) {
            $sql .= \sprintf(' AND %s <> ?', $this->table->quote('id'));
            $params[] = $exceptRecordId;
            $types[] = Types::GUID;
        }

        return false !== $this->connection->fetchOne($sql.' LIMIT 1', $params, $types);
    }

    public function isTakenTogether(ObjectDefinitionDto $object, array $fields, array $values, ?string $exceptRecordId = null): bool
    {
        // Soft deleted records are left out: the unique index is partial (WHERE deleted_at IS NULL).
        $where = [$this->table->column('deleted_at', 't').' IS NULL'];
        $params = [];
        $types = [];
        foreach ($fields as $name) {
            $field = $object->field($name);
            $column = $this->table->column($field->columnName, 't');
            if (\array_key_exists($name, $values)) {
                if (null === $values[$name]) {
                    return false;
                }
                $where[] = $column.' = ?';
                $params[] = $values[$name];
                $types[] = $this->table->typeOf($field);
            } elseif (null !== $exceptRecordId) {
                // NULL there compares as unknown: an empty stored value matches nothing.
                $where[] = \sprintf('%s = (SELECT %s FROM %s WHERE %s = ?)', $column, $this->table->column($field->columnName), $this->table->table($object), $this->table->column('id'));
                $params[] = $exceptRecordId;
                $types[] = Types::GUID;
            } else {
                return false;
            }
        }
        if (null !== $exceptRecordId) {
            $where[] = $this->table->column('id', 't').' <> ?';
            $params[] = $exceptRecordId;
            $types[] = Types::GUID;
        }

        $sql = \sprintf('SELECT 1 FROM %s t WHERE %s LIMIT 1', $this->table->table($object), implode(' AND ', $where));

        return false !== $this->connection->fetchOne($sql, $params, $types);
    }
}
