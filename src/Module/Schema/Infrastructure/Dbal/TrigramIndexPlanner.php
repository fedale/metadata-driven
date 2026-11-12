<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Search\Contract\SearchConfigurationInterface;
use App\Shared\Tenant\CurrentTenant;
use Doctrine\DBAL\Connection;

/**
 * GIN trigram indexes on the searched text columns of an object table, so that the
 * case-insensitive LIKE '%term%' of record search uses an index.
 *
 * They are expression indexes, which DBAL's schema comparator does not model: they are
 * planned here, by name prefix, and kept out of the table comparison.
 */
final class TrigramIndexPlanner
{
    public const string PREFIX = 'trgm_';

    public function __construct(
        private readonly Connection $connection,
        private readonly SearchConfigurationInterface $search,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly TableDefinitionFactory $tables,
        private readonly CurrentTenant $tenant,
    ) {
    }

    /**
     * Only text columns are indexed: a cast of a date or a number to text is not
     * IMMUTABLE, so PostgreSQL refuses it in an index (those fields are still searched).
     *
     * The indexed expression, LOWER("column"), must stay the one the record search runs.
     *
     * @return array<string, string> index name => CREATE INDEX statement
     */
    public function desired(ObjectDefinitionDto $object): array
    {
        $statements = [];
        foreach ($this->search->fieldsFor($object) as $fieldName) {
            $field = $object->field($fieldName);
            $type = $this->fieldTypes->get($field->type)->columnSpec($field->settings)->type;
            if (!\in_array($type, [ColumnType::String, ColumnType::Text], true)) {
                continue;
            }

            $name = $this->tables->identifier(self::PREFIX.$object->tableName.'_'.$field->columnName);
            $statements[$name] = \sprintf(
                'CREATE INDEX %s ON %s USING gin (LOWER(%s) gin_trgm_ops)',
                $this->connection->quoteSingleIdentifier($name),
                $this->qualifiedTable($object),
                $this->connection->quoteSingleIdentifier($field->columnName),
            );
        }

        return $statements;
    }

    /**
     * @return list<string> names of the trigram indexes the table has today
     */
    public function existing(ObjectDefinitionDto $object): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE schemaname = ? AND tablename = ? AND indexname LIKE 'trgm\\_%' ORDER BY indexname",
            [$this->tenant->dataSchema(), $object->tableName],
        );

        return $names;
    }

    public function dropStatement(string $indexName): string
    {
        return \sprintf(
            'DROP INDEX %s.%s',
            $this->connection->quoteSingleIdentifier($this->tenant->dataSchema()),
            $this->connection->quoteSingleIdentifier($indexName),
        );
    }

    public static function isTrigramIndex(string $indexName): bool
    {
        return str_starts_with($indexName, self::PREFIX);
    }

    private function qualifiedTable(ObjectDefinitionDto $object): string
    {
        return $this->connection->quoteSingleIdentifier($this->tenant->dataSchema()).'.'.$this->connection->quoteSingleIdentifier($object->tableName);
    }
}
