<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Domain\Repository\FileReferences;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final class DbalFileReferences implements FileReferences
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordTable $table,
        private readonly MetadataRegistryInterface $metadata,
        private readonly FieldTypeRegistryInterface $fieldTypes,
    ) {
    }

    public function objectsHolding(string $fileId): array
    {
        $objects = [];
        foreach ($this->metadata->allObjects() as $object) {
            $conditions = [];
            foreach ($object->fields as $field) {
                // A field holding several files keeps them in a link table of its own.
                if (null !== $field->fileList()) {
                    $conditions[] = \sprintf(
                        '%s IN (SELECT %s FROM %s WHERE %s = :file)',
                        $this->table->column('id'),
                        $this->table->quote('record_id'),
                        $this->table->fileListTable($object, $field),
                        $this->table->quote('target_id'),
                    );
                } elseif (WidgetKind::File === $this->fieldTypes->get($field->type)->widget($field->settings)->kind) {
                    $conditions[] = \sprintf('%s = :file', $this->table->column($field->columnName));
                }
            }
            if ([] === $conditions) {
                continue;
            }
            // Every file column, and the target_id of every file list, is indexed (as the
            // foreign key to core.file).
            $sql = \sprintf(
                'SELECT 1 FROM %s WHERE (%s) AND %s IS NULL LIMIT 1',
                $this->table->table($object),
                implode(' OR ', $conditions),
                $this->table->column('deleted_at'),
            );
            if (false !== $this->connection->fetchOne($sql, ['file' => $fileId], ['file' => Types::GUID])) {
                $objects[] = $object->name;
            }
        }

        return $objects;
    }
}
