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
                if ($column->name === $table->primaryKey[0] || $this->isSystemColumn($column)) {
                    continue;
                }
                $proposal = ColumnProposer::propose($column, isset($uniques[$column->name]), $targets[$column->name] ?? null);
                // Field names: unique in the object, never a system column's.
                $field = $this->free(ProposedNames::machineName($column->name) ?? 'column', $names);
                $names[$field] = true;
                $columns[$column->name] = new ColumnProposal(
                    $column,
                    array_map(static fn (ColumnMapping $option): ColumnMapping => $option->withNames($field, 'relation' === $option->type ? $option->label : ColumnProposer::label($field)), $proposal->options),
                    $proposal->proposed,
                );
            }
            $proposals[] = new AdoptionProposal($table, $members[$name]['object'], $members[$name]['label'], $members[$name]['label'], $table->keyType ?? KeyType::Uuid, $columns);
        }

        return $proposals;
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
                // The key each object will have, for the relations pointing at it.
                $keys = [];
                foreach ($tables as $name => $table) {
                    $keys[$name] = $table->keyType ?? KeyType::Uuid;
                }
                $statements = [];
                $uniqueSets = [];
                foreach ($requests as $i => $request) {
                    [$statements[$i], $uniqueSets[$i]] = $this->reshape($tables[$request->table], $request, $keys);
                }
                // Every object first, then the fields: a relation finds its target, whichever
                // order the tables point at each other in.
                foreach ($requests as $request) {
                    $this->manager->createObject($request->objectName, $request->labelSingular, $request->labelPlural, null, $keys[$request->table]);
                }
                foreach ($requests as $request) {
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
                // Anything the application's own shape still wants (its indexes, mostly).
                $reports = [];
                foreach ($requests as $i => $request) {
                    $reports[] = new AdoptionReport($request->objectName, [...$statements[$i], ...$this->synchronizer->synchronize($request->objectName)->statements], $dryRun);
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
        if (!$table->isAdoptable() || null === $table->keyType) {
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
        foreach (array_filter($request->columns) as $column => $mapping) {
            if (null !== $mapping->targetTable && !isset($set[$mapping->targetTable])) {
                $reasons[] = new Message('schema.adopt.target_not_adopted', 'Column %column% is a relation to table %table%, which is not adopted with it: adopt both together, or make it no relation.', ['column' => $column, 'table' => $mapping->targetTable]);
            }
        }
        if (ProposedNames::machineName($request->objectName) !== $request->objectName) {
            $reasons[] = new Message('schema.adopt.name_invalid', 'The object\'s name "%name%" is not a machine name: lowercase letters, digits and single underscores, starting with a letter.', ['name' => $request->objectName]);
        } elseif ($this->metadata->hasObject($request->objectName) || isset($this->metadata->archivedObjects()[$request->objectName])) {
            $reasons[] = new Message('schema.adopt.name_taken', 'An object is named "%name%" already: choose another name.', ['name' => $request->objectName]);
        }
        foreach ($table->columns as $column) {
            if ($column->name === $table->primaryKey[0] || $this->isSystemColumn($column)) {
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

    private static function databaseError(DbalException $e): string
    {
        $previous = $e->getPrevious();
        $message = (null !== $previous ? $previous : $e)->getMessage();

        // The driver's message after its SQLSTATE: "ERROR:  integer out of range".
        return trim((string) preg_replace('/^.*?ERROR:\s*/s', '', (string) strtok($message, "\n")));
    }
}
