<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ManyToManyOnDelete;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\OnDelete;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Shared\Database\CoreSchema;
use App\Shared\Database\DataSchema;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Builds the desired DBAL tables for an object definition: its own table, and one link table
 * per many-to-many field.
 *
 * Field columns are always nullable: "required" is enforced by validation, so that a
 * required field can be added to a table that already holds records.
 *
 * The archived fields keep their columns (and indexes, and foreign keys), and their link
 * tables: the database holds their values until the field is purged.
 */
final class TableDefinitionFactory
{
    public function __construct(
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    public function build(ObjectDefinitionDto $object): Table
    {
        $table = Table::editor()
            ->setUnquotedName($this->nonEmpty($object->tableName), DataSchema::NAME)
            ->addColumn($this->column('id', Types::GUID, notNull: true))
            ->addColumn($this->column('created_at', Types::DATETIME_IMMUTABLE, notNull: true))
            ->addColumn($this->column('updated_at', Types::DATETIME_IMMUTABLE, notNull: true))
            ->addColumn($this->column('created_by', Types::GUID))
            ->addColumn($this->column('deleted_at', Types::DATETIME_IMMUTABLE))
            // Manual order of the records, any integer: negatives go before the default.
            ->addColumn($this->column('sort', Types::INTEGER, notNull: true, default: 0))
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    // Same name PostgreSQL would generate, so introspection compares equal.
                    ->setUnquotedName($this->identifier($object->tableName.'_pkey'))
                    ->setUnquotedColumnNames('id')
                    ->create(),
            );

        foreach ($this->allFields($object) as $field) {
            if (!$field->storesColumn()) {
                continue;
            }
            $table->addColumn($this->fieldColumn($field));
            if ($field->isUnique) {
                $table->addIndex($this->uniqueIndex($object->tableName, $field->columnName));
            }
            $relation = $field->relation();
            $coreTable = $this->referencedCoreTable($field);
            if (null !== $relation || null !== $coreTable) {
                // PostgreSQL does not index referencing columns: joins and the delete rules need it.
                if (!$field->isUnique) {
                    $table->addIndex(Index::editor()
                        ->setUnquotedName($this->identifier(\sprintf('idx_%s_%s', $object->tableName, $field->columnName)))
                        ->setUnquotedColumnNames($this->nonEmpty($field->columnName))
                        ->create());
                }
                $table->addForeignKeyConstraint(null !== $relation
                    ? $this->foreignKey($object, $field, $relation)
                    : $this->coreForeignKey($object, $field, $this->nonEmpty($coreTable)));
            }
        }

        return $table->create();
    }

    /**
     * The link tables of the many-to-many fields of an object, archived ones included, keyed
     * by field id. A field going through a junction object has none: its links are the
     * junction's records.
     *
     * A link is a row (record_id, target_id), the pair being the primary key; created_at and
     * created_by say when and by whom it was made. The index on target_id serves the inverse
     * lists, the delete rules and the filters, which all start from the target.
     *
     * @return array<string, Table>
     */
    public function buildLinkTables(ObjectDefinitionDto $object): array
    {
        $tables = [];
        foreach ($this->allFields($object) as $field) {
            $settings = $field->manyToMany();
            if (null === $settings || $settings->goesThrough()) {
                continue;
            }
            $names = $this->linkNames($object, $field);
            $tables[$field->id] = Table::editor()
                ->setUnquotedName($names['table'], DataSchema::NAME)
                ->addColumn($this->column('record_id', Types::GUID, notNull: true))
                ->addColumn($this->column('target_id', Types::GUID, notNull: true))
                ->addColumn($this->column('created_at', Types::DATETIME_IMMUTABLE, notNull: true))
                ->addColumn($this->column('created_by', Types::GUID))
                ->addPrimaryKeyConstraint(
                    PrimaryKeyConstraint::editor()
                        ->setUnquotedName($names['constraints']['pkey'])
                        ->setUnquotedColumnNames('record_id', 'target_id')
                        ->create(),
                )
                ->addIndex(Index::editor()
                    ->setUnquotedName($names['indexes']['target'])
                    ->setUnquotedColumnNames('target_id')
                    ->create())
                // Redundant with the primary key, but DBAL wants an index on the columns of
                // each foreign key alone, and would otherwise add one named after a hash of the
                // table name: a rename of the table would then drop and rebuild it.
                ->addIndex(Index::editor()
                    ->setUnquotedName($names['indexes']['record'])
                    ->setUnquotedColumnNames('record_id')
                    ->create())
                // The records are soft deleted: these rules only act when rows are purged. A
                // record purged takes its links with it; a linked record purged is refused
                // while a restrict link holds it.
                ->addForeignKeyConstraint($this->foreignKeyTo($names['constraints']['fk_record'], 'record_id', $object->tableName, ReferentialAction::CASCADE))
                ->addForeignKeyConstraint($this->foreignKeyTo(
                    $names['constraints']['fk_target'],
                    'target_id',
                    $this->targetTableName($settings->target),
                    match ($settings->onDelete) {
                        ManyToManyOnDelete::Unlink => ReferentialAction::CASCADE,
                        ManyToManyOnDelete::Restrict => ReferentialAction::RESTRICT,
                    },
                ))
                ->create();
        }

        return $tables;
    }

    /**
     * The name of the link table of a many-to-many field, and of its indexes and constraints.
     *
     * @return array{table: non-empty-string, indexes: array{target: non-empty-string, record: non-empty-string}, constraints: array{pkey: non-empty-string, fk_record: non-empty-string, fk_target: non-empty-string}}
     */
    public function linkNames(ObjectDefinitionDto $object, FieldDefinitionDto $field): array
    {
        $table = DataSchema::linkTable($object->name, $field->columnName);

        return [
            'table' => $table,
            'indexes' => [
                'target' => $this->identifier(\sprintf('idx_%s_target_id', $table)),
                'record' => $this->identifier(\sprintf('idx_%s_record_id', $table)),
            ],
            'constraints' => [
                'pkey' => $this->identifier($table.'_pkey'),
                'fk_record' => $this->identifier(\sprintf('fk_%s_record_id', $table)),
                'fk_target' => $this->identifier(\sprintf('fk_%s_target_id', $table)),
            ],
        ];
    }

    /**
     * The core table a field's column points at (a file field: core.file), if any.
     */
    private function referencedCoreTable(FieldDefinitionDto $field): ?string
    {
        return $this->fieldTypes->get($field->type)->columnSpec($field->settings)->referencesCoreTable;
    }

    /**
     * RESTRICT: a row of the core table (a stored file) is only removed once no record points
     * at it any more, records in the bin and archived fields included.
     *
     * @param non-empty-string $coreTable
     */
    private function coreForeignKey(ObjectDefinitionDto $object, FieldDefinitionDto $field, string $coreTable): ForeignKeyConstraint
    {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($this->identifier(\sprintf('fk_%s_%s', $object->tableName, $field->columnName)))
            ->setUnquotedReferencingColumnNames($this->nonEmpty($field->columnName))
            ->setUnquotedReferencedTableName($coreTable, CoreSchema::NAME)
            ->setUnquotedReferencedColumnNames('id')
            ->setOnDeleteAction(ReferentialAction::RESTRICT)
            ->create();
    }

    /**
     * Records are soft deleted, so the delete rules are applied by the application; the
     * physical action mirrors them for the day rows are purged.
     */
    private function foreignKey(ObjectDefinitionDto $object, FieldDefinitionDto $field, RelationSettings $relation): ForeignKeyConstraint
    {
        return $this->foreignKeyTo(
            $this->identifier(\sprintf('fk_%s_%s', $object->tableName, $field->columnName)),
            $field->columnName,
            $this->targetTableName($relation->target),
            match ($relation->onDelete) {
                OnDelete::SetNull => ReferentialAction::SET_NULL,
                OnDelete::Restrict => ReferentialAction::RESTRICT,
                OnDelete::Cascade => ReferentialAction::CASCADE,
            },
        );
    }

    /**
     * @param non-empty-string $name
     */
    private function foreignKeyTo(string $name, string $column, string $targetTable, ReferentialAction $onDelete): ForeignKeyConstraint
    {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames($this->nonEmpty($column))
            ->setUnquotedReferencedTableName($this->nonEmpty($targetTable), DataSchema::NAME)
            ->setUnquotedReferencedColumnNames('id')
            ->setOnDeleteAction($onDelete)
            ->create();
    }

    /**
     * An archived field may point at an archived object: its table is still there.
     */
    private function targetTableName(string $target): string
    {
        $definition = $this->metadata->hasObject($target)
            ? $this->metadata->getObject($target)
            : ($this->metadata->archivedObjects()[$target] ?? $this->metadata->getObject($target));

        return $definition->tableName;
    }

    /**
     * @return list<FieldDefinitionDto> the active fields, then the archived ones
     */
    private function allFields(ObjectDefinitionDto $object): array
    {
        return [...array_values($object->fields), ...array_values($object->archivedFields)];
    }

    /**
     * @return non-empty-string
     */
    public function uniqueIndexName(string $tableName, string $columnName): string
    {
        return $this->identifier(\sprintf('uniq_%s_%s', $tableName, $columnName));
    }

    /**
     * The names of the indexes and constraints of a table, keyed by what they stand for:
     * "pkey", and "<kind>:<field id>" for the index, unique index or foreign key of a field;
     * under "links", the names of the link table of each many-to-many field, by field id.
     * Two definitions of one object (before and after a rename) pair up by key.
     *
     * @return array{indexes: array<string, non-empty-string>, constraints: array<string, non-empty-string>, links: array<string, array{table: non-empty-string, indexes: array<string, non-empty-string>, constraints: array<string, non-empty-string>}>}
     */
    public function objectNames(ObjectDefinitionDto $object): array
    {
        $names = ['indexes' => [], 'constraints' => ['pkey' => $this->identifier($object->tableName.'_pkey')], 'links' => []];
        foreach ($this->allFields($object) as $field) {
            if (!$field->storesColumn()) {
                if (!$field->manyToMany()?->goesThrough()) {
                    $names['links'][$field->id] = $this->linkNames($object, $field);
                }
                continue;
            }
            if ($field->isUnique) {
                $names['indexes']['uniq:'.$field->id] = $this->uniqueIndexName($object->tableName, $field->columnName);
            }
            if (null !== $field->relation() || null !== $this->referencedCoreTable($field)) {
                if (!$field->isUnique) {
                    $names['indexes']['idx:'.$field->id] = $this->identifier(\sprintf('idx_%s_%s', $object->tableName, $field->columnName));
                }
                $names['constraints']['fk:'.$field->id] = $this->identifier(\sprintf('fk_%s_%s', $object->tableName, $field->columnName));
            }
        }

        return $names;
    }

    private function fieldColumn(FieldDefinitionDto $field): Column
    {
        $spec = $this->fieldTypes->get($field->type)->columnSpec($field->settings);

        $column = Column::editor()
            ->setUnquotedName($this->nonEmpty($field->columnName))
            ->setTypeName($spec->type->value)
            ->setNotNull(false);
        if (null !== $spec->length) {
            $column->setLength($spec->length);
        }
        if (null !== $spec->precision) {
            $column->setPrecision($spec->precision);
        }
        if (null !== $spec->scale) {
            $column->setScale($spec->scale);
        }

        return $column->create();
    }

    private function column(string $name, string $type, bool $notNull = false, mixed $default = null): Column
    {
        return Column::editor()
            ->setUnquotedName($this->nonEmpty($name))
            ->setTypeName($type)
            ->setNotNull($notNull)
            ->setDefaultValue($default)
            ->create();
    }

    private function uniqueIndex(string $tableName, string $columnName): Index
    {
        return Index::editor()
            ->setUnquotedName($this->uniqueIndexName($tableName, $columnName))
            ->setType(IndexType::UNIQUE)
            ->setUnquotedColumnNames($this->nonEmpty($columnName))
            ->create();
    }

    /**
     * @return non-empty-string
     */
    public function identifier(string $name): string
    {
        return DataSchema::identifier($name);
    }

    /**
     * @return non-empty-string
     */
    private function nonEmpty(string $name): string
    {
        return '' !== $name ? $name : throw new \LogicException('Identifiers cannot be empty.');
    }
}
