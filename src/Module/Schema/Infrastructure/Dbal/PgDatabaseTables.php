<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Schema\Application\Table\ProposedNames;
use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Module\Schema\Contract\Dto\DatabaseColumn;
use App\Module\Schema\Contract\Dto\DatabaseForeignKey;
use App\Module\Schema\Contract\Dto\DatabaseTable;
use App\Module\Schema\Contract\Dto\TableKind;
use App\Shared\Message\Message;
use App\Shared\Workspace\CurrentWorkspace;
use Doctrine\DBAL\Connection;

/**
 * The unmanaged tables of the workspace's data schema, read from the PostgreSQL catalog
 * (pg_class, pg_attribute, pg_constraint...), a handful of queries for the whole schema.
 *
 * Read only: nothing here changes a table.
 */
final class PgDatabaseTables implements DatabaseTablesInterface
{
    // The primary key types an object's key may be: a UUID, or an integer of any size.
    private const array KEY_TYPES = ['uuid' => KeyType::Uuid, 'smallint' => KeyType::Integer, 'integer' => KeyType::Integer, 'bigint' => KeyType::Integer];
    private const array ON_DELETE = ['a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadata,
        private readonly TableDefinitionFactory $tables,
        private readonly CurrentWorkspace $workspace,
    ) {
    }

    public function unmanaged(): array
    {
        return $this->read(null);
    }

    public function find(string $name): ?DatabaseTable
    {
        return $this->read($name)[0] ?? null;
    }

    /**
     * @return list<DatabaseTable>
     */
    private function read(?string $only): array
    {
        $schema = $this->workspace->dataSchema();
        $managed = $this->managedTables();
        $relations = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT c.relname, c.relkind, c.reltuples FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind IN ('r', 'p', 'v', 'm', 'f') AND NOT c.relispartition ORDER BY c.relname",
            [$schema],
        ) as $row) {
            $name = self::text($row['relname']);
            if (!isset($managed[$name]) && (null === $only || $only === $name)) {
                $relations[$name] = $row;
            }
        }
        if ([] === $relations) {
            return [];
        }

        $columns = $this->columns($schema);
        $constraints = $this->constraints($schema);
        $users = $this->users($schema);
        $takenNames = array_fill_keys([...array_keys($this->metadata->allObjects()), ...array_keys($this->metadata->archivedObjects())], true);

        $result = [];
        foreach ($relations as $name => $row) {
            $kind = TableKind::fromRelkind(self::text($row['relkind']));
            $primaryKey = $constraints[$name]['p'][0]['columns'] ?? [];
            $keyType = null;
            if (1 === \count($primaryKey)) {
                $keyType = self::KEY_TYPES[self::baseType($columns[$name][$primaryKey[0]] ?? null)] ?? null;
            }
            $proposed = ProposedNames::forTable($name);
            $rows = is_numeric($row['reltuples']) ? (int) $row['reltuples'] : -1;

            $result[] = new DatabaseTable(
                $name,
                $kind,
                array_values($columns[$name] ?? []),
                $primaryKey,
                array_map(static fn (array $unique): array => $unique['columns'], $constraints[$name]['u'] ?? []),
                array_map(static fn (array $key): DatabaseForeignKey => new DatabaseForeignKey($key['name'], $key['columns'], $key['ref_schema'], $key['ref_table'], $key['ref_columns'], $key['on_delete']), $constraints[$name]['f'] ?? []),
                $rows < 0 ? null : $rows,
                null === $proposed || isset($takenNames[$proposed]) ? null : $proposed,
                $keyType,
                $this->refusals($kind, $primaryKey, $columns[$name] ?? [], $keyType, $users[$name] ?? []),
            );
        }

        return $result;
    }

    /**
     * @param list<string>                  $primaryKey
     * @param array<string, DatabaseColumn> $columns
     * @param list<Message>                 $users      the triggers, rules and views depending on the table
     *
     * @return list<Message>
     */
    private function refusals(TableKind $kind, array $primaryKey, array $columns, ?KeyType $keyType, array $users): array
    {
        if (TableKind::Table !== $kind) {
            return [new Message('schema.table.not_a_table', 'It is a %kind%: only a table can become an object.', ['kind' => $kind->message()])];
        }
        $refusals = [];
        if ([] === $primaryKey) {
            $refusals[] = new Message('schema.table.no_primary_key', 'It has no primary key: give it one first.');
        } elseif (\count($primaryKey) > 1) {
            $refusals[] = new Message('schema.table.composite_key', 'Its primary key has several columns (%columns%): such a table cannot become an object yet.', ['columns' => implode(', ', $primaryKey)]);
        } elseif (null === $keyType) {
            $refusals[] = new Message('schema.table.key_type', 'Its primary key is of type %type%: only a UUID or an integer can be the key of an object.', ['type' => $columns[$primaryKey[0]]->type ?? '?']);
        }

        return [...$refusals, ...$users];
    }

    /**
     * The tables the metadata owns: the objects' (archived ones too) and their link tables.
     *
     * @return array<string, true>
     */
    private function managedTables(): array
    {
        $managed = [];
        foreach ([...array_values($this->metadata->allObjects()), ...array_values($this->metadata->archivedObjects())] as $object) {
            $managed[$object->tableName] = true;
            foreach ($this->tables->objectNames($object)['links'] as $link) {
                $managed[$link['table']] = true;
            }
        }

        return $managed;
    }

    /**
     * @return array<string, array<string, DatabaseColumn>> table => column name => column, in the order of the table
     */
    private function columns(string $schema): array
    {
        $columns = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT c.relname, a.attname, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull, pg_get_expr(d.adbin, d.adrelid) AS column_default, a.attidentity
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE n.nspname = ? AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY c.relname, a.attnum',
            [$schema],
        ) as $row) {
            $name = self::text($row['attname']);
            $columns[self::text($row['relname'])][$name] = new DatabaseColumn(
                $name,
                self::text($row['type']),
                true !== $row['attnotnull'],
                \is_string($row['column_default']) ? $row['column_default'] : null,
                \in_array($row['attidentity'], ['a', 'd'], true),
            );
        }

        return $columns;
    }

    /**
     * The primary keys ("p"), unique constraints ("u") and foreign keys ("f") of each table.
     *
     * @return array<string, array<string, list<array{name: string, columns: list<string>, ref_schema: string, ref_table: string, ref_columns: list<string>, on_delete: string}>>>
     */
    private function constraints(string $schema): array
    {
        $constraints = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT c.relname, con.conname, con.contype, con.confdeltype, rn.nspname AS ref_schema, rc.relname AS ref_table,
                    array_to_json(ARRAY(SELECT a.attname FROM unnest(con.conkey) WITH ORDINALITY k(num, ord) JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.num ORDER BY k.ord))::text AS columns,
                    array_to_json(ARRAY(SELECT a.attname FROM unnest(con.confkey) WITH ORDINALITY k(num, ord) JOIN pg_attribute a ON a.attrelid = con.confrelid AND a.attnum = k.num ORDER BY k.ord))::text AS ref_columns
             FROM pg_constraint con
             JOIN pg_class c ON c.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_class rc ON rc.oid = con.confrelid
             LEFT JOIN pg_namespace rn ON rn.oid = rc.relnamespace
             WHERE n.nspname = ? AND con.contype IN ('p', 'u', 'f')
             ORDER BY c.relname, con.conname",
            [$schema],
        ) as $row) {
            $constraints[self::text($row['relname'])][self::text($row['contype'])][] = [
                'name' => self::text($row['conname']),
                'columns' => self::names($row['columns']),
                'ref_schema' => self::text($row['ref_schema']),
                'ref_table' => self::text($row['ref_table']),
                'ref_columns' => self::names($row['ref_columns']),
                'on_delete' => self::ON_DELETE[self::text($row['confdeltype'])] ?? 'NO ACTION',
            ];
        }

        return $constraints;
    }

    /**
     * What depends on each table and would break once adoption renames it or its columns: its
     * triggers, its rules, the views reading it.
     *
     * @return array<string, list<Message>>
     */
    private function users(string $schema): array
    {
        $users = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT c.relname, t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND NOT t.tgisinternal ORDER BY c.relname, t.tgname',
            [$schema],
        ) as $row) {
            $users[self::text($row['relname'])][] = new Message('schema.table.trigger', 'Trigger %name% acts on it, and would break: drop it first.', ['name' => self::text($row['tgname'])]);
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT tablename, rulename FROM pg_rules WHERE schemaname = ? ORDER BY tablename, rulename',
            [$schema],
        ) as $row) {
            $users[self::text($row['tablename'])][] = new Message('schema.table.rule', 'Rule %name% acts on it, and would break: drop it first.', ['name' => self::text($row['rulename'])]);
        }
        foreach ($this->connection->fetchAllAssociative(
            "SELECT DISTINCT src.relname, vn.nspname || '.' || v.relname AS view
             FROM pg_depend d
             JOIN pg_rewrite r ON r.oid = d.objid
             JOIN pg_class v ON v.oid = r.ev_class
             JOIN pg_namespace vn ON vn.oid = v.relnamespace
             JOIN pg_class src ON src.oid = d.refobjid
             JOIN pg_namespace sn ON sn.oid = src.relnamespace
             WHERE d.classid = 'pg_rewrite'::regclass AND d.refclassid = 'pg_class'::regclass AND v.oid <> src.oid AND sn.nspname = ?
             ORDER BY 1, 2",
            [$schema],
        ) as $row) {
            $users[self::text($row['relname'])][] = new Message('schema.table.view', 'View %name% reads it, and would break: drop it first.', ['name' => self::text($row['view'])]);
        }

        return $users;
    }

    /**
     * The type of a column without its modifiers: "character varying(80)" is "character varying".
     */
    private static function baseType(?DatabaseColumn $column): string
    {
        return null === $column ? '' : (string) preg_replace('/\(.*$/', '', $column->type);
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $json): array
    {
        $names = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
