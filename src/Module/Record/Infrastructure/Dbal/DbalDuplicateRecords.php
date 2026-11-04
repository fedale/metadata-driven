<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\DuplicateRecordsInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Doctrine\DBAL\Connection;

final class DbalDuplicateRecords implements DuplicateRecordsInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordTable $table,
    ) {
    }

    public function count(string $objectName, array $fields): int
    {
        $object = $this->metadata->getObject($objectName);
        $columns = array_map(fn (string $field): string => $this->table->column($object->field($field)->columnName), $fields);
        // The rows the partial unique index would cover: live, with every column filled.
        $sql = \sprintf(
            'SELECT COALESCE(SUM(n), 0) FROM (SELECT COUNT(*) AS n FROM %s WHERE %s IS NULL AND %s GROUP BY %s HAVING COUNT(*) > 1) d',
            $this->table->table($object),
            $this->table->column('deleted_at'),
            implode(' AND ', array_map(static fn (string $column): string => $column.' IS NOT NULL', $columns)),
            implode(', ', $columns),
        );
        $count = $this->connection->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
    }
}
