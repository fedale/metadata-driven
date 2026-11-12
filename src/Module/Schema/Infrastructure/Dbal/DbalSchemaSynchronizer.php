<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Schema\Contract\Dto\SchemaPlan;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Schema\Domain\Model\SchemaChangeLog;
use App\Module\Schema\Domain\Repository\SchemaChangeLogRepository;
use App\Shared\Tenant\CurrentTenant;
use App\Shared\Transaction\TransactionRunner;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Symfony\Component\Uid\Uuid;

final class DbalSchemaSynchronizer implements SchemaSynchronizerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadata,
        private readonly TableDefinitionFactory $tables,
        private readonly TrigramIndexPlanner $trigramIndexes,
        private readonly UniqueTogetherIndexPlanner $uniqueTogetherIndexes,
        private readonly SchemaChangeLogRepository $logs,
        private readonly TransactionRunner $transactions,
        private readonly CurrentTenant $tenant,
    ) {
    }

    public function plan(string $objectName, bool $allowDestructive = false): SchemaPlan
    {
        $object = $this->metadata->getObject($objectName);
        $desired = $this->tables->build($object);
        $qualifiedName = $this->tenant->dataSchema().'.'.$object->tableName;
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();

        // Planned on their own, by name: kept out of the DBAL comparison.
        $desiredIndexes = [...$this->trigramIndexes->desired($object), ...$this->uniqueTogetherIndexes->desired($object)];

        // Renames are never guessed: a dropped plus an added column stay two separate changes.
        $comparator = $schemaManager->createComparator(new ComparatorConfig(
            detectRenamedColumns: false,
            detectRenamedIndexes: false,
            reportModifiedIndexes: false,
        ));

        $skipped = [];
        // After the object's table: a link table's foreign keys point at it.
        $linkStatements = [];
        foreach ($this->tables->buildLinkTables($object) as $link) {
            $linkStatements = [...$linkStatements, ...$this->tableStatements($link, $comparator, $allowDestructive, $skipped)];
        }

        try {
            $actual = $this->withoutPlannedIndexes(
                $schemaManager->introspectTableByUnquotedName($this->nonEmpty($object->tableName), $this->tenant->dataSchema()),
            );
        } catch (TableDoesNotExist) {
            return new SchemaPlan(
                $objectName,
                $qualifiedName,
                true,
                [...$platform->getCreateTableSQL($desired), ...array_values($desiredIndexes), ...$linkStatements],
                $skipped,
            );
        }

        $diff = $comparator->compareTables($actual, $desired);
        if (!$allowDestructive) {
            $diff = $comparator->compareTables($actual, $this->withoutDestructiveChanges($actual, $desired, $diff, $skipped));
        }

        // Trigram and unique-together indexes hold no data: they follow the search
        // configuration and the unique sets both ways. Dropped first: a set whose index is
        // rebuilt under another name must not be checked twice.
        $existingIndexes = [...$this->trigramIndexes->existing($object), ...$this->uniqueTogetherIndexes->existing($object)];
        $indexStatements = [];
        foreach (array_diff($existingIndexes, array_keys($desiredIndexes)) as $name) {
            $indexStatements[] = UniqueTogetherIndexPlanner::isUniqueTogetherIndex($name)
                ? $this->uniqueTogetherIndexes->dropStatement($name)
                : $this->trigramIndexes->dropStatement($name);
        }
        foreach ($desiredIndexes as $name => $statement) {
            if (!\in_array($name, $existingIndexes, true)) {
                $indexStatements[] = $statement;
            }
        }

        return new SchemaPlan(
            $objectName,
            $qualifiedName,
            false,
            [...($diff->isEmpty() ? [] : $platform->getAlterTableSQL($diff)), ...$indexStatements, ...$linkStatements],
            $skipped,
        );
    }

    /**
     * The DDL bringing one table (a link table) in line with its definition: created when
     * missing, otherwise altered under the same rules as an object's table.
     *
     * @param list<string> $skipped
     *
     * @return list<string>
     */
    private function tableStatements(Table $desired, Comparator $comparator, bool $allowDestructive, array &$skipped): array
    {
        $platform = $this->connection->getDatabasePlatform();
        try {
            $actual = $this->connection->createSchemaManager()->introspectTableByUnquotedName(
                $this->nonEmpty($desired->getObjectName()->getUnqualifiedName()->getValue()),
                $this->tenant->dataSchema(),
            );
        } catch (TableDoesNotExist) {
            return $platform->getCreateTableSQL($desired);
        }

        $diff = $comparator->compareTables($actual, $desired);
        if (!$allowDestructive) {
            $diff = $comparator->compareTables($actual, $this->withoutDestructiveChanges($actual, $desired, $diff, $skipped));
        }

        return $diff->isEmpty() ? [] : $platform->getAlterTableSQL($diff);
    }

    /**
     * The trigram and unique-together indexes are planned on their own: hide them from the
     * DBAL comparison, which would otherwise report them as indexes the metadata does not
     * define.
     */
    private function withoutPlannedIndexes(Table $table): Table
    {
        $editor = $table->edit();
        foreach ($table->getIndexes() as $index) {
            $name = $index->getObjectName()->getIdentifier()->getValue();
            if (TrigramIndexPlanner::isTrigramIndex($name) || UniqueTogetherIndexPlanner::isUniqueTogetherIndex($name)) {
                $editor->dropIndex($index->getObjectName());
            }
        }

        return $editor->create();
    }

    public function synchronize(string $objectName, bool $allowDestructive = false): SchemaPlan
    {
        return $this->transactions->run(function () use ($objectName, $allowDestructive): SchemaPlan {
            return $this->execute($this->plan($objectName, $allowDestructive));
        });
    }

    public function rename(ObjectDefinitionDto $before, ObjectDefinitionDto $after): SchemaPlan
    {
        $q = $this->connection->quoteSingleIdentifier(...);
        $statements = [];
        if ($before->tableName !== $after->tableName) {
            $statements[] = \sprintf('ALTER TABLE %s.%s RENAME TO %s', $this->tenant->dataSchema(), $q($before->tableName), $q($after->tableName));
        }
        $table = $this->tenant->dataSchema().'.'.$q($after->tableName);
        $columns = [];
        foreach ([...array_values($before->fields), ...array_values($before->archivedFields)] as $field) {
            $columns[$field->id] = $field->columnName;
        }
        foreach ([...array_values($after->fields), ...array_values($after->archivedFields)] as $field) {
            // A many-to-many field has no column: its link table is renamed below.
            if (!$field->storesColumn()) {
                continue;
            }
            $old = $columns[$field->id] ?? null;
            if (null !== $old && $old !== $field->columnName) {
                $statements[] = \sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $table, $q($old), $q($field->columnName));
            }
        }
        $old = $this->tables->objectNames($before);
        $new = $this->tables->objectNames($after);
        $statements = [...$statements, ...$this->renameNames($table, $old, $new)];
        // A link table is named after its object and its field: it follows both.
        foreach ($new['links'] as $fieldId => $link) {
            $previous = $old['links'][$fieldId] ?? null;
            if (null === $previous) {
                continue;
            }
            if ($previous['table'] !== $link['table']) {
                $statements[] = \sprintf('ALTER TABLE %s.%s RENAME TO %s', $this->tenant->dataSchema(), $q($previous['table']), $q($link['table']));
            }
            $statements = [...$statements, ...$this->renameNames($this->tenant->dataSchema().'.'.$q($link['table']), $previous, $link)];
        }

        return $this->transactions->run(function () use ($after, $statements): SchemaPlan {
            $plan = new SchemaPlan($after->name, $this->tenant->dataSchema().'.'.$after->tableName, false, $statements, []);
            $this->execute($plan);
            // The trigram and unique-together indexes hold no data and are named after the table
            // and the columns: the synchronization drops the old ones and builds them again under
            // the new names.
            $this->synchronize($after->name);

            return $plan;
        });
    }

    public function dropTable(ObjectDefinitionDto $object): SchemaPlan
    {
        $qualified = $this->tenant->dataSchema().'.'.$object->tableName;
        // Its link tables first: their foreign keys point at it.
        $statements = [];
        foreach ($this->tables->objectNames($object)['links'] as $link) {
            $statements[] = $this->dropTableStatement($link['table']);
        }
        $statements[] = $this->dropTableStatement($object->tableName);
        $plan = new SchemaPlan($object->name, $qualified, false, $statements, []);

        return $this->transactions->run(fn (): SchemaPlan => $this->execute($plan));
    }

    public function dropColumn(ObjectDefinitionDto $object, FieldDefinitionDto $field): SchemaPlan
    {
        if (!$field->storesColumn()) {
            // A field going through a junction object owns no table: the junction keeps its records.
            if (true === $field->manyToMany()?->goesThrough()) {
                return new SchemaPlan($object->name, $this->tenant->dataSchema().'.'.$object->tableName, false, [], []);
            }
            $plan = new SchemaPlan($object->name, $this->tenant->dataSchema().'.'.$object->tableName, false, [
                $this->dropTableStatement($this->tables->linkNames($object, $field)['table']),
            ], []);

            return $this->transactions->run(fn (): SchemaPlan => $this->execute($plan));
        }

        // Its indexes and foreign key go with it.
        $plan = new SchemaPlan($object->name, $this->tenant->dataSchema().'.'.$object->tableName, false, [\sprintf(
            'ALTER TABLE %s.%s DROP COLUMN %s',
            $this->tenant->dataSchema(),
            $this->connection->quoteSingleIdentifier($object->tableName),
            $this->connection->quoteSingleIdentifier($field->columnName),
        )], []);

        return $this->transactions->run(fn (): SchemaPlan => $this->execute($plan));
    }

    private function dropTableStatement(string $tableName): string
    {
        return \sprintf('DROP TABLE %s.%s', $this->tenant->dataSchema(), $this->connection->quoteSingleIdentifier($tableName));
    }

    /**
     * Renames the indexes and constraints of one table whose names changed, paired by key.
     *
     * @param string                                                                                        $table quoted, qualified
     * @param array{indexes: array<string, non-empty-string>, constraints: array<string, non-empty-string>} $old
     * @param array{indexes: array<string, non-empty-string>, constraints: array<string, non-empty-string>} $new
     *
     * @return list<string>
     */
    private function renameNames(string $table, array $old, array $new): array
    {
        $q = $this->connection->quoteSingleIdentifier(...);
        $statements = [];
        foreach ($new['indexes'] as $key => $name) {
            if (isset($old['indexes'][$key]) && $old['indexes'][$key] !== $name) {
                $statements[] = \sprintf('ALTER INDEX %s.%s RENAME TO %s', $this->tenant->dataSchema(), $q($old['indexes'][$key]), $q($name));
            }
        }
        foreach ($new['constraints'] as $key => $name) {
            if (isset($old['constraints'][$key]) && $old['constraints'][$key] !== $name) {
                $statements[] = \sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $table, $q($old['constraints'][$key]), $q($name));
            }
        }

        return $statements;
    }

    /**
     * Runs the statements of a plan and logs them; an empty plan is not logged.
     */
    private function execute(SchemaPlan $plan): SchemaPlan
    {
        if ($plan->isEmpty()) {
            return $plan;
        }
        foreach ($plan->statements as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->logs->add(new SchemaChangeLog(Uuid::v7(), $plan->objectName, $plan->tableName, $plan->statements, $plan->skipped));

        return $plan;
    }

    /**
     * Returns the desired table with every destructive change reverted to the actual state,
     * and describes what was reverted in $skipped.
     *
     * @param list<string> $skipped
     */
    private function withoutDestructiveChanges(Table $actual, Table $desired, TableDiff $diff, array &$skipped): Table
    {
        $editor = $desired->edit();

        foreach ($diff->getDroppedColumns() as $column) {
            $editor->addColumn($column);
            $skipped[] = \sprintf('Column "%s" is not defined in the metadata: not dropped.', $this->rawName($column->getObjectName()));
        }
        foreach ($diff->getChangedColumns() as $columnDiff) {
            $old = $columnDiff->getOldColumn();
            $editor->dropColumn($columnDiff->getNewColumn()->getObjectName())->addColumn($old);
            $skipped[] = \sprintf('Column "%s" differs from the metadata: not altered.', $this->rawName($old->getObjectName()));
        }
        // A modified index is reported as dropped and re-added under the same name.
        foreach ($diff->getDroppedIndexes() as $index) {
            $name = $index->getObjectName();
            if ($desired->hasIndex($name->toString())) {
                $editor->dropIndex($name);
                $skipped[] = \sprintf('Index "%s" differs from the metadata: not altered.', $this->rawName($name));
            } else {
                $skipped[] = \sprintf('Index "%s" is not defined in the metadata: not dropped.', $this->rawName($name));
            }
            $editor->addIndex($index);
        }

        return $editor->create();
    }

    /**
     * Identifier without SQL quoting, for human readable messages.
     */
    private function rawName(UnqualifiedName $name): string
    {
        return $name->getIdentifier()->getValue();
    }

    /**
     * @return non-empty-string
     */
    private function nonEmpty(string $name): string
    {
        return '' !== $name ? $name : throw new \LogicException('Table names cannot be empty.');
    }
}
