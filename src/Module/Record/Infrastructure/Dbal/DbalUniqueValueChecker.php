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
}
