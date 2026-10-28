<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\FieldValueUsageInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Doctrine\DBAL\Connection;

final class DbalFieldValueUsage implements FieldValueUsageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordTable $table,
    ) {
    }

    public function countRecordsHolding(string $objectName, string $fieldName, string $value): int
    {
        $object = $this->metadata->getObject($objectName);
        $sql = \sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s = ? AND %s IS NULL',
            $this->table->table($object),
            $this->table->column($object->field($fieldName)->columnName),
            $this->table->column('deleted_at'),
        );
        $count = $this->connection->fetchOne($sql, [$value]);

        return is_numeric($count) ? (int) $count : 0;
    }
}
