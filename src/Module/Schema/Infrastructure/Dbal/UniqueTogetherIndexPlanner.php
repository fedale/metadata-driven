<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Shared\Database\DataSchema;
use Doctrine\DBAL\Connection;

/**
 * The unique indexes of the field sets an object keeps unique together.
 *
 * They are partial (WHERE deleted_at IS NULL): a soft deleted record no longer holds its
 * values, so a junction record removed and added again is not refused. DBAL's comparator
 * does not model the predicate: they are planned here, by name prefix, and kept out of the
 * table comparison. They hold no data, so they follow the metadata both ways: built when a
 * set is added, dropped when it is removed.
 *
 * The name lists the columns ("uniqc_<table>__<column>__<column>"): a set that changes, or a
 * field or object renamed, gets a new name, so the old index is dropped and the new one built.
 */
final class UniqueTogetherIndexPlanner
{
    public const string PREFIX = 'uniqc_';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, string> index name => CREATE UNIQUE INDEX statement
     */
    public function desired(ObjectDefinitionDto $object): array
    {
        $statements = [];
        foreach ($object->uniqueTogether as $set) {
            $columns = array_map(static fn (string $field): string => $object->field($field)->columnName, $set);
            $name = DataSchema::identifier(self::PREFIX.$object->tableName.'__'.implode('__', $columns));
            $statements[$name] = \sprintf(
                'CREATE UNIQUE INDEX %s ON %s.%s (%s) WHERE %s IS NULL',
                $this->connection->quoteSingleIdentifier($name),
                $this->connection->quoteSingleIdentifier(DataSchema::NAME),
                $this->connection->quoteSingleIdentifier($object->tableName),
                implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $columns)),
                $this->connection->quoteSingleIdentifier('deleted_at'),
            );
        }

        return $statements;
    }

    /**
     * @return list<string> names of the unique-together indexes the table has today
     */
    public function existing(ObjectDefinitionDto $object): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE schemaname = ? AND tablename = ? AND indexname LIKE 'uniqc\\_%' ORDER BY indexname",
            [DataSchema::NAME, $object->tableName],
        );

        return $names;
    }

    public function dropStatement(string $indexName): string
    {
        return \sprintf(
            'DROP INDEX %s.%s',
            $this->connection->quoteSingleIdentifier(DataSchema::NAME),
            $this->connection->quoteSingleIdentifier($indexName),
        );
    }

    public static function isUniqueTogetherIndex(string $indexName): bool
    {
        return str_starts_with($indexName, self::PREFIX);
    }
}
