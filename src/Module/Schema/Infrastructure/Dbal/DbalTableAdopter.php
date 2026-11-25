<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Schema\Application\Table\ColumnProposer;
use App\Module\Schema\Application\Table\ProposedNames;
use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Module\Schema\Contract\Dto\AdoptionProposal;
use App\Module\Schema\Contract\Dto\AdoptionReport;
use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Dto\ColumnMapping;
use App\Module\Schema\Contract\Dto\ColumnProposal;
use App\Module\Schema\Contract\Dto\DatabaseColumn;
use App\Module\Schema\Contract\Dto\DatabaseTable;
use App\Module\Schema\Contract\Dto\JunctionMapping;
use App\Module\Schema\Contract\Exception\AdoptionRefused;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Schema\Contract\TableAdopterInterface;
use App\Shared\Database\DataSchema;
use App\Shared\Message\Message;
use App\Shared\Transaction\TransactionRunner;
use App\Shared\Workspace\CurrentWorkspace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Adopts a table in one transaction: first the table is brought to the shape the Schema module
 * would build for the object (so that the metadata, written next, finds every column it wants,
 * and the synchronization has nothing left to do), then the object, its fields and its unique
 * sets are created through the Metadata module, as from the admin.
 *
 * The table keeps its records and their keys. What changes, in order: the foreign keys and
 * unique constraints the fields will own are dropped (the synchronization makes them again, the
 * application's way); the columns are renamed to their fields' names (and a column left out that
 * would clash, to "<name>_legacy"); their types become the ones the fields ask for; they lose
 * NOT NULL and their default ("required" is the field's); the key becomes "id", a UUID with no
 * default or a bigint identity column going on from the largest key; the system columns are
 * added (or a column of that name and type reused); the table is renamed c_<object>.
 */
final class DbalTableAdopter implements TableAdopterInterface
{
    // The system columns a table gets (ObjectDefinition::SYSTEM_COLUMNS, but "id"), and the types
    // a column of that name must have to be reused as one.
    private const array SYSTEM_COLUMNS = [
        'created_at' => ['type' => Types::DATETIME_IMMUTABLE, 'reuse' => ['timestamp without time zone', 'timestamp with time zone']],
        'updated_at' => ['type' => Types::DATETIME_IMMUTABLE, 'reuse' => ['timestamp without time zone', 'timestamp with time zone']],
        'created_by' => ['type' => Types::GUID, 'reuse' => ['uuid']],
        'deleted_at' => ['type' => Types::DATETIME_IMMUTABLE, 'reuse' => ['timestamp without time zone', 'timestamp with time zone']],
        'sort' => ['type' => Types::INTEGER, 'reuse' => ['smallint', 'integer']],
    ];
    private const array ON_DELETE = ['CASCADE' => 'cascade', 'SET NULL' => 'set_null', 'RESTRICT' => 'restrict', 'NO ACTION' => 'restrict'];

    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseTablesInterface $tables,
        private readonly MetadataRegistryInterface $metadata,
        private readonly MetadataManagerInterface $manager,
        private readonly SchemaSynchronizerInterface $synchronizer,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly TransactionRunner $transactions,
        private readonly CurrentWorkspace $workspace,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function propose(string $table): AdoptionProposal
    {
        return $this->proposeSet([$table])[0];
    }

    public function proposeSet(array $tables): array
    {
        $found = [];
        foreach (array_values(array_unique($tables)) as $name) {
            $found[$name] = $this->adoptable($name);
        }
        // The objects the tables would become: proposed names, each once.
        $members = [];
        $taken = [];
        foreach ($found as $name => $table) {
            $objectName = $table->proposedName ?? '';
            if ('' !== $objectName) {
                $objectName = $this->free($objectName, $taken);
                $taken[$objectName] = true;
            }
            $members[$name] = ['object' => $objectName, 'label' => '' === $objectName ? $name : ColumnProposer::label($objectName)];
        }

        $proposals = [];
        foreach (self::ordered($found) as $name) {
            $table = $found[$name];
            $uniques = array_fill_keys(array_merge(...array_map(static fn (array $unique): array => 1 === \count($unique) ? $unique : [], $table->uniques)), true);
            $targets = $this->targets($table, $found, $members);
            $columns = [];
            $names = [];
            foreach ($table->columns as $column) {
                if (\in_array($column->name, self::keyColumns($table), true) || $this->isSystemColumn($column)) {
                    continue;
                }
                $proposal = ColumnProposer::propose($column, isset($uniques[$column->name]), $targets[$column->name] ?? null, self::isJson($column) && $this->holdsStringLists($table, $column->name));
                // Field names: unique in the object, never a system column's.
                $field = $this->free(ProposedNames::machineName($column->name) ?? 'column', $names);
                $names[$field] = true;
                $columns[$column->name] = new ColumnProposal(
                    $column,
                    array_map(static fn (ColumnMapping $option): ColumnMapping => $option->withNames($field, 'relation' === $option->type ? $option->label : ColumnProposer::label($field)), $proposal->options),
                    $proposal->proposed,
                );
            }
            $junction = null;
            if ($table->junction) {
                [$sourceColumn, $targetColumn] = $table->primaryKey;
                $missing = array_values(array_unique(array_filter(
                    array_map(fn (string $column): ?string => isset($targets[$column]) ? null : $this->referencedTable($table, $column), $table->primaryKey),
                    is_string(...),
                )));
                if ([] !== $missing) {
                    throw new AdoptionRefused([new Message('schema.adopt.junction_alone', 'Table %table% links %tables%: adopt it with them.', ['table' => $name, 'tables' => implode(', ', $missing)])]);
                }
                $fieldName = self::plural($targets[$targetColumn]['object']);
                // Columns of its own besides the pair: a junction object keeps them as fields.
                $junction = new JunctionMapping($sourceColumn, $targetColumn, $fieldName, ColumnProposer::label($fieldName), [] !== array_diff(array_keys($columns), $table->primaryKey));
            } elseif (null !== ($pair = self::keyedPair($table)) && isset($targets[$pair[0]], $targets[$pair[1]])) {
                // A key of its own, and a pair of relations unique together: a junction object
                // already, which a many-to-many field may go through.
                $fieldName = self::plural($targets[$pair[1]]['object']);
                $junction = new JunctionMapping($pair[0], $pair[1], $fieldName, ColumnProposer::label($fieldName), true);
            }
            $proposals[] = new AdoptionProposal($table, $members[$name]['object'], $members[$name]['label'], $members[$name]['label'], $table->junction ? KeyType::Integer : $table->keyType ?? KeyType::Uuid, $columns, $junction);
        }

        return $proposals;
    }

    /**
     * The columns that are the table's key, and so no field: none for a junction, whose two
     * columns become relations (as a junction object) or the links (as a link table).
     *
     * @return list<string>
     */
    private static function keyColumns(DatabaseTable $table): array
    {
        return $table->junction ? [] : [$table->primaryKey[0]];
    }

    /**
     * A table with a key of its own linking two others: the first unique constraint of two
     * columns, each a foreign key of its own. Null for any other table.
     *
     * @return array{string, string}|null
     */
    private static function keyedPair(DatabaseTable $table): ?array
    {
        if ($table->junction) {
            return null;
        }
        $single = [];
        foreach ($table->foreignKeys as $foreignKey) {
            if (1 === \count($foreignKey->columns)) {
                $single[$foreignKey->columns[0]] = true;
            }
        }
        foreach ($table->uniques as $unique) {
            if (2 === \count($unique) && isset($single[$unique[0]], $single[$unique[1]])) {
                return [$unique[0], $unique[1]];
            }
        }

        return null;
    }

    /**
     * The table a column's foreign key points at, if it has one of its own.
     */
    private function referencedTable(DatabaseTable $table, string $column): ?string
    {
        foreach ($table->foreignKeys as $foreignKey) {
            if ([$column] === $foreignKey->columns) {
                return $foreignKey->referencedTable;
            }
        }

        return null;
    }

    /**
     * A field holding several records is named in the plural: "tag" gives "tags".
     */
    private static function plural(string $name): string
    {
        return str_ends_with($name, 's') ? $name : $name.'s';
    }

    public function related(array $tables): array
    {
        $in = array_fill_keys($tables, true);
        $pointedAt = [];
        $pointing = [];
        foreach ($this->tables->unmanaged() as $table) {
            foreach ($table->foreignKeys as $foreignKey) {
                if ($foreignKey->referencedSchema !== $this->workspace->dataSchema()) {
                    continue;
                }
                if (isset($in[$table->name]) && !isset($in[$foreignKey->referencedTable])) {
                    $pointedAt[$foreignKey->referencedTable] = true;
                }
                if (!isset($in[$table->name]) && isset($in[$foreignKey->referencedTable]) && $table->isAdoptable()) {
                    $pointing[$table->name] = true;
                }
            }
        }
        // Only the tables pointed at that can be adopted (an object's is no candidate).
        $adoptable = [];
        foreach ($this->tables->unmanaged() as $table) {
            if ($table->isAdoptable()) {
                $adoptable[$table->name] = true;
            }
        }

        return [
            'pointedAt' => array_values(array_filter(array_keys($pointedAt), static fn (string $name): bool => isset($adoptable[$name]))),
            'pointing' => array_keys($pointing),
        ];
    }

    public function adopt(AdoptionRequest $request, bool $dryRun = false): AdoptionReport
    {
        return $this->adoptSet([$request], $dryRun)[0];
    }

    public function adoptSet(array $requests, bool $dryRun = false): array
    {
        $tables = [];
        $objects = [];
        foreach ($requests as $request) {
            $tables[$request->table] = $this->adoptable($request->table);
            if (self::isLink($request)) {
                continue;
            }
            if (isset($objects[$request->objectName])) {
                throw new AdoptionRefused([new Message('schema.adopt.name_twice', 'Two tables would become the object "%name%": give one another name.', ['name' => $request->objectName])]);
            }
            $objects[$request->objectName] = $request->table;
        }
        $byTable = array_flip($objects);
        foreach ($requests as $request) {
            $this->checkRequest($tables[$request->table], $request, $byTable);
        }

        try {
            return $this->transactions->run(function () use ($requests, $tables, $byTable, $dryRun): array {
                // The key each object will have, for the relations pointing at it: a junction
                // object gets an integer one.
                $keys = [];
                foreach ($tables as $name => $table) {
                    $keys[$name] = $table->junction ? KeyType::Integer : $table->keyType ?? KeyType::Uuid;
                }
                $statements = [];
                $uniqueSets = [];
                $links = [];
                foreach ($requests as $i => $request) {
                    $table = $tables[$request->table];
                    $junction = $request->junction;
                    if (null !== $junction) {
                        $links[$i] = [
                            'source' => $this->objectOf($table, $junction->sourceColumn, $byTable),
                            'target' => $this->objectOf($table, $junction->targetColumn, $byTable),
                        ];
                    }
                    if (self::isLink($request)) {
                        $statements[$i] = $this->reshapeLink($table, $request, $links[$i]['source'], $links[$i]['target'], $keys);
                        $uniqueSets[$i] = [];
                        continue;
                    }
                    $added = $table->junction ? $this->addKey($table) : [];
                    [$statements[$i], $uniqueSets[$i]] = $this->reshape($table->junction ? $this->adoptable($table->name) : $table, $request, $keys);
                    $statements[$i] = [...$added, ...$statements[$i]];
                    // A key of two columns: unique together now (a unique constraint already
                    // is, as the reshape made it).
                    if (null !== $junction && $table->junction) {
                        // Their pair was the key: unique together now.
                        $uniqueSets[$i][] = [$request->columns[$junction->sourceColumn]->fieldName ?? $junction->sourceColumn, $request->columns[$junction->targetColumn]->fieldName ?? $junction->targetColumn];
                    }
                }
                // Every object first, then the fields: a relation finds its target, whichever
                // order the tables point at each other in.
                foreach ($requests as $request) {
                    if (!self::isLink($request)) {
                        $this->manager->createObject($request->objectName, $request->labelSingular, $request->labelPlural, null, $keys[$request->table]);
                    }
                }
                foreach ($requests as $request) {
                    if (self::isLink($request)) {
                        continue;
                    }
                    foreach (array_filter($request->columns) as $mapping) {
                        $settings = $mapping->settings;
                        if (null !== $mapping->targetTable) {
                            $settings['target'] = $byTable[$mapping->targetTable];
                        }
                        $this->manager->createField($request->objectName, $mapping->fieldName, $mapping->type, $mapping->label, $mapping->required, $mapping->unique, $settings);
                    }
                }
                foreach ($requests as $i => $request) {
                    foreach ($uniqueSets[$i] as $fields) {
                        $this->manager->addUniqueTogether($request->objectName, $fields);
                    }
                }
                // The many-to-many fields last: a junction object needs its pair unique together.
                foreach ($requests as $i => $request) {
                    $junction = $request->junction;
                    if (null === $junction) {
                        continue;
                    }
                    $settings = ['target' => $links[$i]['target']];
                    if ($junction->asObject) {
                        $settings += [
                            'through' => $request->objectName,
                            'throughSource' => $request->columns[$junction->sourceColumn]->fieldName ?? '',
                            'throughTarget' => $request->columns[$junction->targetColumn]->fieldName ?? '',
                        ];
                    } else {
                        $settings['onDelete'] = 'CASCADE' === $this->onDelete($tables[$request->table], $junction->targetColumn) ? 'unlink' : 'restrict';
                    }
                    $this->manager->createField($links[$i]['source'], $junction->fieldName, 'many_to_many', $junction->label, settings: $settings);
                }
                // Anything the application's own shape still wants (its indexes, mostly).
                $reports = [];
                foreach ($requests as $i => $request) {
                    $object = self::isLink($request) ? $links[$i]['source'] : $request->objectName;
                    $reports[] = new AdoptionReport($object, [...$statements[$i], ...$this->synchronizer->synchronize($object)->statements], $dryRun);
                }
                if ($dryRun) {
                    throw new AdoptionDryRun($reports);
                }

                return $reports;
            });
        } catch (AdoptionDryRun $done) {
            $this->entityManager->clear();

            return $done->reports;
        } catch (AdoptionRefused $e) {
            $this->entityManager->clear();

            throw $e;
        } catch (MetadataException $e) {
            $this->entityManager->clear();

            throw new AdoptionRefused([new Message('schema.adopt.metadata', 'The metadata refused it: %reason%', ['reason' => $e->text()])], $e);
        } catch (DbalException $e) {
            $this->entityManager->clear();

            throw new AdoptionRefused([new Message('schema.adopt.database', 'The database refused it: %reason%', ['reason' => self::databaseError($e)])], $e);
        }
    }

    /**
     * The tables in an order where a table comes after those it points at, as far as they do
     * not point at each other in a circle; otherwise in the order given.
     *
     * @param array<string, DatabaseTable> $tables
     *
     * @return list<string>
     */
    private static function ordered(array $tables): array
    {
        $ordered = [];
        $visiting = [];
        $visit = static function (string $name) use (&$visit, &$ordered, &$visiting, $tables): void {
            if (isset($ordered[$name]) || isset($visiting[$name])) {
                return;
            }
            $visiting[$name] = true;
            foreach ($tables[$name]->foreignKeys as $foreignKey) {
                if ($foreignKey->referencedTable !== $name && isset($tables[$foreignKey->referencedTable])) {
                    $visit($foreignKey->referencedTable);
                }
            }
            $ordered[$name] = true;
        };
        foreach (array_keys($tables) as $name) {
            $visit((string) $name);
        }

        return array_map(strval(...), array_keys($ordered));
    }

    /**
     * @throws AdoptionRefused
     */
    private function adoptable(string $name): DatabaseTable
    {
        $table = $this->tables->find($name) ?? throw new AdoptionRefused([new Message('schema.adopt.no_table', 'The data schema has no table "%table%" that no object owns.', ['table' => $name])]);
        if (!$table->isAdoptable() || (null === $table->keyType && !$table->junction)) {
            throw new AdoptionRefused($table->refusals);
        }

        return $table;
    }

    /**
     * Every column but the key and the system columns reused has its say, and a column left out
     * must not stop the application from writing a record. A relation to a table adopted with
     * it needs that table in the set.
     *
     * @param array<string, string> $set the objects the tables adopted together become, by table
     *
     * @throws AdoptionRefused
     */
    private function checkRequest(DatabaseTable $table, AdoptionRequest $request, array $set): void
    {
        $reasons = [];
        $junction = $request->junction;
        if ($table->junction && null === $junction) {
            throw new AdoptionRefused([new Message('schema.adopt.junction_expected', 'Table %table% links two others: say what it becomes, a many-to-many field or a junction object.', ['table' => $table->name])]);
        }
        if (null !== $junction) {
            // The pair of the key, or of the unique constraint of a table with a key of its own.
            $pair = $table->junction ? $table->primaryKey : self::keyedPair($table) ?? [];
            if ([$junction->sourceColumn, $junction->targetColumn] !== $pair && [$junction->targetColumn, $junction->sourceColumn] !== $pair) {
                throw new AdoptionRefused([new Message('schema.adopt.junction_columns', 'The two columns of a many-to-many field are the pair linking %table% to two others: %columns%.', ['table' => $table->name, 'columns' => implode(', ', $pair)])]);
            }
            if (!$table->junction && !$junction->asObject) {
                throw new AdoptionRefused([new Message('schema.adopt.keyed_junction', 'Table %table% has a key of its own: it becomes a junction object, the many-to-many field going through it.', ['table' => $table->name])]);
            }
            if (!$junction->asObject) {
                $extra = array_values(array_filter(array_map(static fn (DatabaseColumn $column): string => $column->name, $table->columns), fn (string $column): bool => !\in_array($column, $table->primaryKey, true) && !\in_array($column, ['created_at', 'created_by'], true)));
                if ([] !== $extra) {
                    throw new AdoptionRefused([new Message('schema.adopt.link_columns', 'As the links of a many-to-many field, its columns %columns% would be lost: make it a junction object.', ['columns' => implode(', ', $extra)])]);
                }

                return;
            }
            if ($table->junction && null !== $table->column('id')) {
                $reasons[] = new Message('schema.adopt.junction_id', 'As a junction object it gets a key of its own, "id": rename its column id first.');
            }
        }
        foreach (array_filter($request->columns) as $column => $mapping) {
            if (null !== $mapping->targetTable && !isset($set[$mapping->targetTable])) {
                $reasons[] = new Message('schema.adopt.target_not_adopted', 'Column %column% is a relation to table %table%, which is not adopted with it: adopt both together, or make it no relation.', ['column' => $column, 'table' => $mapping->targetTable]);
            }
            // A tags field reads lists of strings: anything else would reach the records as is.
            $database = $table->column($column);
            if ('tags' === $mapping->type && null !== $database && (!self::isJson($database) || !$this->holdsStringLists($table, $column))) {
                $reasons[] = new Message('schema.adopt.tags_not_string_lists', 'Column %column% becomes tags only if it is a JSON column whose values are all lists of strings: make it a text.', ['column' => $column]);
            }
        }
        if (ProposedNames::machineName($request->objectName) !== $request->objectName) {
            $reasons[] = new Message('schema.adopt.name_invalid', 'The object\'s name "%name%" is not a machine name: lowercase letters, digits and single underscores, starting with a letter.', ['name' => $request->objectName]);
        } elseif ($this->metadata->hasObject($request->objectName) || isset($this->metadata->archivedObjects()[$request->objectName])) {
            $reasons[] = new Message('schema.adopt.name_taken', 'An object is named "%name%" already: choose another name.', ['name' => $request->objectName]);
        }
        foreach ($table->columns as $column) {
            if (\in_array($column->name, self::keyColumns($table), true) || $this->isSystemColumn($column)) {
                continue;
            }
            if (!\array_key_exists($column->name, $request->columns)) {
                $reasons[] = new Message('schema.adopt.column_missing', 'Column %column%: say which field it becomes, or leave it out.', ['column' => $column->name]);
                continue;
            }
            if (null === $request->columns[$column->name] && !$column->nullable && null === $column->default && !$column->identity) {
                $reasons[] = new Message('schema.adopt.required_left_out', 'Column %column% cannot be left out: it may not be empty and has no default, so no record could be written.', ['column' => $column->name]);
            }
        }
        foreach (array_keys($request->columns) as $name) {
            if (null === $table->column($name)) {
                $reasons[] = new Message('schema.adopt.unknown_column', 'The table has no column %column%.', ['column' => $name]);
            }
        }
        if ([] !== $reasons) {
            throw new AdoptionRefused($reasons);
        }
    }

    /**
     * The table brought to the shape of the object's.
     *
     * @param array<string, KeyType> $keys the key of each table adopted with it
     *
     * @return array{list<string>, list<list<string>>} the statements run, and the sets of fields
     *                                                 unique together its unique constraints become
     */
    private function reshape(DatabaseTable $table, AdoptionRequest $request, array $keys): array
    {
        $statements = [];
        $run = function (string $sql) use (&$statements): void {
            $this->connection->executeStatement($sql);
            $statements[] = $sql;
        };
        $quote = $this->connection->quoteSingleIdentifier(...);
        $qualified = fn (string $name): string => $quote($this->workspace->dataSchema()).'.'.$quote($name);
        $t = $qualified($table->name);
        $key = $table->primaryKey[0];
        $mapped = array_filter($request->columns);
        // Read once: they are dropped and the table renamed on the way.
        $uniqueConstraints = $this->uniqueConstraints($table);

        // 1. The constraints the fields will own, made again by the synchronization.
        foreach ($table->foreignKeys as $foreignKey) {
            if (1 === \count($foreignKey->columns) && 'relation' === ($mapped[$foreignKey->columns[0]]->type ?? null)) {
                $run(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $t, $quote($foreignKey->name)));
            }
        }
        foreach ($uniqueConstraints as $name => $columns) {
            $owned = 1 === \count($columns)
                ? true === ($mapped[$columns[0]]->unique ?? false)
                : [] === array_diff($columns, array_keys($mapped));
            if ($owned) {
                $run(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $t, $quote($name)));
            }
        }

        // 2. The names: through temporary ones, so that two columns may trade theirs.
        $final = ['id' => $key];
        foreach ($mapped as $column => $mapping) {
            $final[$mapping->fieldName] = $column;
        }
        $renames = [];
        foreach ($table->columns as $column) {
            $target = array_search($column->name, $final, true);
            if (false === $target && $column->name !== $key && !isset($mapped[$column->name]) && !$this->isSystemColumn($column)
                && (isset($final[$column->name]) || isset(self::SYSTEM_COLUMNS[$column->name]) || 'id' === $column->name)) {
                // Left out, in the way of a field or a system column.
                $target = $this->free($column->name.'_legacy', array_fill_keys(array_column($table->columns, 'name'), true));
            }
            if (\is_string($target) && $target !== $column->name) {
                $renames[$column->name] = $target;
            }
        }
        $step = 0;
        $temporary = [];
        foreach ($renames as $from => $to) {
            $temporary[$from] = '__adopt_'.++$step;
            $run(\sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $t, $quote($from), $quote($temporary[$from])));
        }
        foreach ($renames as $from => $to) {
            $run(\sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $t, $quote($temporary[$from]), $quote($to)));
        }

        // 3. The fields' columns: their types, no NOT NULL, no default.
        foreach ($mapped as $column => $mapping) {
            $database = $table->column($column) ?? throw new \LogicException('Checked.');
            $c = $quote($mapping->fieldName);
            $type = $this->declaration($mapping, $keys);
            if (self::sameType($database->type, $type)) {
                // Nothing to convert.
            } else {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s::%s', $t, $c, $type, $c, $type));
            }
            if (!$database->nullable) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP NOT NULL', $t, $c));
            }
            if (null !== $database->default) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $c));
            }
            if ($database->identity) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP IDENTITY', $t, $c));
            }
            // No label at all is null for a tags field, as when a record is written.
            if ('tags' === $mapping->type) {
                $run(\sprintf("UPDATE %s SET %s = NULL WHERE %s = '[]'::jsonb", $t, $c, $c));
            }
        }

        // 4. The key.
        $keyColumn = $table->column($key) ?? throw new \LogicException('Checked.');
        $id = $quote('id');
        if (KeyType::Uuid === $table->keyType) {
            if (null !== $keyColumn->default) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $id));
            }
        } else {
            if (!self::sameType($keyColumn->type, 'BIGINT')) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE BIGINT', $t, $id));
            }
            if (!$keyColumn->identity) {
                // A serial: its sequence goes, an identity column numbers the records instead.
                $sequence = $this->connection->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$this->workspace->dataSchema().'.'.$quote($table->name), 'id']);
                if (null !== $keyColumn->default) {
                    $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $id));
                }
                if (\is_string($sequence)) {
                    $run(\sprintf('DROP SEQUENCE %s', $sequence));
                }
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s ADD GENERATED BY DEFAULT AS IDENTITY', $t, $id));
            }
            $largest = $this->connection->fetchOne(\sprintf('SELECT MAX(%s) FROM %s', $id, $t));
            if (is_numeric($largest)) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s RESTART WITH %d', $t, $id, (int) $largest + 1));
            }
        }

        // 5. The system columns.
        $existing = [];
        foreach ($table->columns as $column) {
            if ($this->isSystemColumn($column)) {
                $existing[$column->name] = $column;
            }
        }
        foreach (self::SYSTEM_COLUMNS as $name => $spec) {
            $c = $quote($name);
            $type = $this->typeDeclaration($spec['type']);
            $notNull = \in_array($name, ['created_at', 'updated_at', 'sort'], true);
            $fill = match ($name) {
                'created_at', 'updated_at' => 'LOCALTIMESTAMP(0)',
                'sort' => '0',
                default => null,
            };
            $column = $existing[$name] ?? null;
            if (null === $column) {
                $run(null === $fill
                    ? \sprintf('ALTER TABLE %s ADD COLUMN %s %s', $t, $c, $type)
                    : \sprintf('ALTER TABLE %s ADD COLUMN %s %s NOT NULL DEFAULT %s', $t, $c, $type, $fill));
                if (null !== $fill && 'sort' !== $name) {
                    $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $c));
                }
                continue;
            }
            if (!self::sameType($column->type, $type)) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s::%s', $t, $c, $type, $c, $type));
            }
            if (null !== $fill) {
                $run(\sprintf('UPDATE %s SET %s = %s WHERE %s IS NULL', $t, $c, $fill, $c));
            }
            if ($notNull && $column->nullable) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s SET NOT NULL', $t, $c));
            }
            if (!$notNull && !$column->nullable) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP NOT NULL', $t, $c));
            }
            if ('sort' === $name) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s SET DEFAULT 0', $t, $c));
            } elseif (null !== $column->default) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $c));
            }
        }

        // 6. The table and its primary key, named as the object's.
        $tableName = DataSchema::identifier('c_'.$request->objectName);
        $primaryKey = $this->connection->fetchOne(
            "SELECT con.conname FROM pg_constraint con JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND con.contype = 'p'",
            [$this->workspace->dataSchema(), $table->name],
        );
        $pkey = DataSchema::identifier($tableName.'_pkey');
        if (\is_string($primaryKey) && $primaryKey !== $pkey) {
            $run(\sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $t, $quote($primaryKey), $quote($pkey)));
        }
        if ($tableName !== $table->name) {
            $run(\sprintf('ALTER TABLE %s RENAME TO %s', $t, $quote($tableName)));
        }

        $uniqueSets = [];
        foreach ($uniqueConstraints as $columns) {
            if (\count($columns) > 1 && [] === array_diff($columns, array_keys($mapped))) {
                $uniqueSets[] = array_map(static fn (string $column): string => $mapped[$column]->fieldName, $columns);
            }
        }

        return [$statements, $uniqueSets];
    }

    /**
     * The unique constraints of the table, by name.
     *
     * @return array<string, list<string>>
     */
    private function uniqueConstraints(DatabaseTable $table): array
    {
        $constraints = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT con.conname, array_to_json(ARRAY(SELECT a.attname FROM unnest(con.conkey) WITH ORDINALITY k(num, ord) JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.num ORDER BY k.ord))::text AS columns
             FROM pg_constraint con JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relname = ? AND con.contype = 'u' ORDER BY con.conname",
            [$this->workspace->dataSchema(), $table->name],
        ) as $row) {
            $columns = \is_string($row['columns']) ? json_decode($row['columns'], true) : null;
            if (\is_string($row['conname']) && \is_array($columns)) {
                $constraints[$row['conname']] = array_values(array_filter($columns, is_string(...)));
            }
        }

        return $constraints;
    }

    /**
     * The objects the table's foreign keys point at, by column: a key of one column pointing at
     * the key of an object of this workspace.
     *
     * And the tables adopted with it, by the objects they will become.
     *
     * @param array<string, DatabaseTable>                        $set     the tables adopted together, by name
     * @param array<string, array{object: string, label: string}> $members the objects they become
     *
     * @return array<string, array{object: string, label: string, onDelete: string, table: string|null}>
     */
    private function targets(DatabaseTable $table, array $set, array $members): array
    {
        $byTable = [];
        foreach ($this->metadata->allObjects() as $object) {
            $byTable[$object->tableName] = $object;
        }
        $targets = [];
        foreach ($table->foreignKeys as $foreignKey) {
            $object = $byTable[$foreignKey->referencedTable] ?? null;
            if (1 !== \count($foreignKey->columns) || $foreignKey->referencedSchema !== $this->workspace->dataSchema()) {
                continue;
            }
            $onDelete = self::ON_DELETE[$foreignKey->onDelete] ?? 'restrict';
            $member = $set[$foreignKey->referencedTable] ?? null;
            if (null !== $member && $member->primaryKey === $foreignKey->referencedColumns) {
                $targets[$foreignKey->columns[0]] = ['object' => $members[$member->name]['object'], 'label' => $members[$member->name]['label'], 'onDelete' => $onDelete, 'table' => $member->name];
            } elseif ($object instanceof ObjectDefinitionDto && ['id'] === $foreignKey->referencedColumns) {
                $targets[$foreignKey->columns[0]] = ['object' => $object->name, 'label' => $object->labelSingular, 'onDelete' => $onDelete, 'table' => null];
            }
        }

        return $targets;
    }

    /**
     * A column of a system column's name and type: reused as that system column.
     */
    private static function isJson(DatabaseColumn $column): bool
    {
        return \in_array(ColumnProposer::parse($column->type)[0], ['json', 'jsonb'], true);
    }

    /**
     * Whether every value of a JSON column is a list of strings (true when it holds none).
     */
    private function holdsStringLists(DatabaseTable $table, string $column): bool
    {
        $c = $this->connection->quoteSingleIdentifier($column);
        // CASE, not OR: the elements of a value are read only once it is known to be a list.
        $mismatch = $this->connection->fetchOne(\sprintf(
            "SELECT EXISTS (SELECT 1 FROM %s.%s WHERE %s IS NOT NULL AND CASE WHEN jsonb_typeof(%s::jsonb) = 'array' THEN EXISTS (SELECT 1 FROM jsonb_array_elements(%s::jsonb) e WHERE jsonb_typeof(e) <> 'string') ELSE TRUE END)",
            $this->connection->quoteSingleIdentifier($this->workspace->dataSchema()),
            $this->connection->quoteSingleIdentifier($table->name),
            $c, $c, $c,
        ));

        return false === $mismatch;
    }

    private function isSystemColumn(DatabaseColumn $column): bool
    {
        $spec = self::SYSTEM_COLUMNS[$column->name] ?? null;

        return null !== $spec && \in_array(ColumnProposer::parse($column->type)[0], $spec['reuse'], true);
    }

    /**
     * The SQL type a field's column has, as the Schema module builds it.
     */
    /**
     * @param array<string, KeyType> $keys the key of each table adopted with it
     */
    private function declaration(ColumnMapping $mapping, array $keys): string
    {
        if ('relation' === $mapping->type && null !== $mapping->targetTable) {
            return $this->typeDeclaration(($keys[$mapping->targetTable] ?? KeyType::Uuid)->dbalType());
        }
        if ('relation' === $mapping->type) {
            $target = \is_string($mapping->settings['target'] ?? null) && $this->metadata->hasObject($mapping->settings['target']) ? $this->metadata->getObject($mapping->settings['target'])->keyType : KeyType::Uuid;

            return $this->typeDeclaration($target->dbalType());
        }
        $type = $this->fieldTypes->get($mapping->type);
        $spec = $type->columnSpec($mapping->settings + $type->defaultSettings());

        return $this->typeDeclaration($spec->type->value, $spec->length, $spec->precision, $spec->scale);
    }

    private function typeDeclaration(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        return Type::getType($type)->getSQLDeclaration(array_filter(['length' => $length, 'precision' => $precision, 'scale' => $scale], static fn (?int $value): bool => null !== $value), $this->connection->getDatabasePlatform());
    }

    /**
     * Whether a type as PostgreSQL writes it ("character varying(80)") is the one DBAL declares
     * ("VARCHAR(80)").
     */
    private static function sameType(string $catalog, string $declaration): bool
    {
        $normalize = static fn (string $type): string => (string) preg_replace(
            ['/\bvarchar\b/', '/\bint\b|\bint4\b/', '/\bint8\b/', '/\bint2\b/', '/\s*,\s*/', '/^timestamp(\(\d+\))?$/', '/^numeric$/'],
            ['character varying', 'integer', 'bigint', 'smallint', ',', 'timestamp$1 without time zone', 'numeric'],
            strtolower(trim($type)),
        );

        return $normalize($catalog) === $normalize($declaration);
    }

    /**
     * A name not in $taken: $name, or $name_2, $name_3...
     *
     * @param array<string, true> $taken
     */
    private function free(string $name, array $taken): string
    {
        $reserved = ['id' => true, ...array_fill_keys(array_keys(self::SYSTEM_COLUMNS), true)];
        $candidate = $name;
        for ($i = 2; isset($taken[$candidate]) || isset($reserved[$candidate]); ++$i) {
            $candidate = isset($reserved[$name]) && 2 === $i ? $name.'_legacy' : $name.'_'.$i;
        }

        return $candidate;
    }

    /**
     * A table linking two others adopted as the links of a many-to-many field: no object.
     */
    private static function isLink(AdoptionRequest $request): bool
    {
        return null !== $request->junction && !$request->junction->asObject;
    }

    /**
     * The object a column's foreign key points at: one a table of the set becomes, or one there
     * is.
     *
     * @param array<string, string> $set the objects the tables adopted together become, by table
     *
     * @throws AdoptionRefused
     */
    private function objectOf(DatabaseTable $table, string $column, array $set): string
    {
        $referenced = $this->referencedTable($table, $column) ?? '';
        if (isset($set[$referenced])) {
            return $set[$referenced];
        }
        foreach ($this->metadata->allObjects() as $object) {
            if ($object->tableName === $referenced) {
                return $object->name;
            }
        }

        throw new AdoptionRefused([new Message('schema.adopt.junction_alone', 'Table %table% links %tables%: adopt it with them.', ['table' => $table->name, 'tables' => $referenced])]);
    }

    /**
     * The rule on delete of a column's foreign key, as SQL says it.
     */
    private function onDelete(DatabaseTable $table, string $column): string
    {
        foreach ($table->foreignKeys as $foreignKey) {
            if ([$column] === $foreignKey->columns) {
                return $foreignKey->onDelete;
            }
        }

        return 'NO ACTION';
    }

    /**
     * A junction table made a table of records: a key of its own, an identity column numbering
     * the rows there are, instead of its pair of columns.
     *
     * @return list<string> the statements run
     */
    private function addKey(DatabaseTable $table): array
    {
        $quote = $this->connection->quoteSingleIdentifier(...);
        $t = $quote($this->workspace->dataSchema()).'.'.$quote($table->name);
        $statements = [];
        $primaryKey = $this->primaryKeyName($table->name);
        if (null !== $primaryKey) {
            $statements[] = \sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $t, $quote($primaryKey));
        }
        $statements[] = \sprintf('ALTER TABLE %s ADD COLUMN %s BIGINT GENERATED BY DEFAULT AS IDENTITY', $t, $quote('id'));
        $statements[] = \sprintf('ALTER TABLE %s ADD PRIMARY KEY (%s)', $t, $quote('id'));
        foreach ($statements as $sql) {
            $this->connection->executeStatement($sql);
        }

        return $statements;
    }

    /**
     * A junction table made the link table of a many-to-many field, as the Schema module builds
     * it: (record_id, target_id) of the two objects' keys, the key, created_at and created_by.
     * The synchronization adds its indexes and foreign keys.
     *
     * @param array<string, KeyType> $keys the key of each table adopted with it
     *
     * @return list<string> the statements run
     */
    private function reshapeLink(DatabaseTable $table, AdoptionRequest $request, string $source, string $target, array $keys): array
    {
        $junction = $request->junction ?? throw new \LogicException('A junction.');
        $statements = [];
        $run = function (string $sql) use (&$statements): void {
            $this->connection->executeStatement($sql);
            $statements[] = $sql;
        };
        $quote = $this->connection->quoteSingleIdentifier(...);
        $t = $quote($this->workspace->dataSchema()).'.'.$quote($table->name);
        $link = DataSchema::linkTable($source, $junction->fieldName);
        $keyOf = function (string $column) use ($table, $keys): KeyType {
            $referenced = $this->referencedTable($table, $column) ?? '';
            if (isset($keys[$referenced])) {
                return $keys[$referenced];
            }
            foreach ($this->metadata->allObjects() as $object) {
                if ($object->tableName === $referenced) {
                    return $object->keyType;
                }
            }

            return KeyType::Uuid;
        };

        foreach ($table->foreignKeys as $foreignKey) {
            $run(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $t, $quote($foreignKey->name)));
        }
        $primaryKey = $this->primaryKeyName($table->name);
        if (null !== $primaryKey) {
            $run(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $t, $quote($primaryKey)));
        }
        $run(\sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $t, $quote($junction->sourceColumn), $quote('__adopt_record')));
        $run(\sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $t, $quote($junction->targetColumn), $quote('__adopt_target')));
        $run(\sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $t, $quote('__adopt_record'), $quote('record_id')));
        $run(\sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $t, $quote('__adopt_target'), $quote('target_id')));
        foreach (['record_id' => $keyOf($junction->sourceColumn), 'target_id' => $keyOf($junction->targetColumn)] as $column => $key) {
            $type = $this->typeDeclaration($key->dbalType());
            $current = $table->column('record_id' === $column ? $junction->sourceColumn : $junction->targetColumn);
            if (null === $current || !self::sameType($current->type, $type)) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s::%s', $t, $quote($column), $type, $quote($column), $type));
            }
            if (null !== $current?->default) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $quote($column)));
            }
        }
        $timestamp = $this->typeDeclaration(Types::DATETIME_IMMUTABLE);
        $createdAt = $table->column('created_at');
        if (null === $createdAt) {
            $run(\sprintf('ALTER TABLE %s ADD COLUMN %s %s NOT NULL DEFAULT LOCALTIMESTAMP(0)', $t, $quote('created_at'), $timestamp));
            $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $quote('created_at')));
        } else {
            if (!self::sameType($createdAt->type, $timestamp)) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s::%s', $t, $quote('created_at'), $timestamp, $quote('created_at'), $timestamp));
            }
            $run(\sprintf('UPDATE %s SET %s = LOCALTIMESTAMP(0) WHERE %s IS NULL', $t, $quote('created_at'), $quote('created_at')));
            $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s SET NOT NULL', $t, $quote('created_at')));
            if (null !== $createdAt->default) {
                $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $t, $quote('created_at')));
            }
        }
        $uuid = $this->typeDeclaration(Types::GUID);
        $createdBy = $table->column('created_by');
        if (null === $createdBy) {
            $run(\sprintf('ALTER TABLE %s ADD COLUMN %s %s', $t, $quote('created_by'), $uuid));
        } elseif (!self::sameType($createdBy->type, $uuid)) {
            $run(\sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s::%s', $t, $quote('created_by'), $uuid, $quote('created_by'), $uuid));
        }
        $run(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s PRIMARY KEY (%s, %s)', $t, $quote(DataSchema::identifier($link.'_pkey')), $quote('record_id'), $quote('target_id')));
        if ($link !== $table->name) {
            $run(\sprintf('ALTER TABLE %s RENAME TO %s', $t, $quote($link)));
        }

        return $statements;
    }

    private function primaryKeyName(string $table): ?string
    {
        $name = $this->connection->fetchOne(
            "SELECT con.conname FROM pg_constraint con JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND con.contype = 'p'",
            [$this->workspace->dataSchema(), $table],
        );

        return \is_string($name) ? $name : null;
    }

    private static function databaseError(DbalException $e): string
    {
        $previous = $e->getPrevious();
        $message = (null !== $previous ? $previous : $e)->getMessage();

        // The driver's message after its SQLSTATE: "ERROR:  integer out of range".
        return trim((string) preg_replace('/^.*?ERROR:\s*/s', '', (string) strtok($message, "\n")));
    }
}
