<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Schema\Contract\Dto\SchemaPlan;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Schema\Domain\Model\SchemaChangeLog;
use App\Module\Schema\Domain\Repository\SchemaChangeLogRepository;
use App\Shared\Database\DataSchema;
use App\Shared\Transaction\TransactionRunner;
use Doctrine\DBAL\Connection;
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
        private readonly SchemaChangeLogRepository $logs,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function plan(string $objectName, bool $allowDestructive = false): SchemaPlan
    {
        $object = $this->metadata->getObject($objectName);
        $desired = $this->tables->build($object);
        $qualifiedName = DataSchema::NAME.'.'.$object->tableName;
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();

        $desiredTrigramIndexes = $this->trigramIndexes->desired($object);

        try {
            $actual = $this->withoutTrigramIndexes(
                $schemaManager->introspectTableByUnquotedName($this->nonEmpty($object->tableName), DataSchema::NAME),
            );
        } catch (TableDoesNotExist) {
            return new SchemaPlan(
                $objectName,
                $qualifiedName,
                true,
                [...$platform->getCreateTableSQL($desired), ...array_values($desiredTrigramIndexes)],
                [],
            );
        }

        // Renames are never guessed: a dropped plus an added column stay two separate changes.
        $comparator = $schemaManager->createComparator(new ComparatorConfig(
            detectRenamedColumns: false,
            detectRenamedIndexes: false,
            reportModifiedIndexes: false,
        ));
        $diff = $comparator->compareTables($actual, $desired);

        $skipped = [];
        if (!$allowDestructive) {
            $diff = $comparator->compareTables($actual, $this->withoutDestructiveChanges($actual, $desired, $diff, $skipped));
        }

        // Trigram indexes hold no data: they follow the search configuration both ways.
        $existingTrigramIndexes = $this->trigramIndexes->existing($object);
        $indexStatements = [];
        foreach ($desiredTrigramIndexes as $name => $statement) {
            if (!\in_array($name, $existingTrigramIndexes, true)) {
                $indexStatements[] = $statement;
            }
        }
        foreach (array_diff($existingTrigramIndexes, array_keys($desiredTrigramIndexes)) as $name) {
            $indexStatements[] = $this->trigramIndexes->dropStatement($name);
        }

        return new SchemaPlan(
            $objectName,
            $qualifiedName,
            false,
            [...($diff->isEmpty() ? [] : $platform->getAlterTableSQL($diff)), ...$indexStatements],
            $skipped,
        );
    }

    /**
     * The trigram indexes are planned on their own: hide them from the DBAL comparison,
     * which would otherwise report them as indexes the metadata does not define.
     */
    private function withoutTrigramIndexes(Table $table): Table
    {
        $editor = $table->edit();
        foreach ($table->getIndexes() as $index) {
            if (TrigramIndexPlanner::isTrigramIndex($index->getObjectName()->getIdentifier()->getValue())) {
                $editor->dropIndex($index->getObjectName());
            }
        }

        return $editor->create();
    }

    public function synchronize(string $objectName, bool $allowDestructive = false): SchemaPlan
    {
        return $this->transactions->run(function () use ($objectName, $allowDestructive): SchemaPlan {
            $plan = $this->plan($objectName, $allowDestructive);
            if ($plan->isEmpty()) {
                return $plan;
            }

            foreach ($plan->statements as $statement) {
                $this->connection->executeStatement($statement);
            }
            $this->logs->add(new SchemaChangeLog(Uuid::v7(), $plan->objectName, $plan->tableName, $plan->statements, $plan->skipped));

            return $plan;
        });
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
