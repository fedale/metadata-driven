<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
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
 * Builds the desired DBAL table for an object definition.
 *
 * Field columns are always nullable: "required" is enforced by validation, so that a
 * required field can be added to a table that already holds records.
 *
 * The archived fields keep their columns (and indexes, and foreign keys): the table holds
 * their values until the field is purged.
 */
final class TableDefinitionFactory
{
    // PostgreSQL silently truncates longer identifiers.
    private const int MAX_IDENTIFIER_LENGTH = 63;

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
            ->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    // Same name PostgreSQL would generate, so introspection compares equal.
                    ->setUnquotedName($this->identifier($object->tableName.'_pkey'))
                    ->setUnquotedColumnNames('id')
                    ->create(),
            );

        foreach ([...array_values($object->fields), ...array_values($object->archivedFields)] as $field) {
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
        // An archived field may point at an archived object: its table is still there.
        $target = $this->metadata->hasObject($relation->target)
            ? $this->metadata->getObject($relation->target)
            : ($this->metadata->archivedObjects()[$relation->target] ?? $this->metadata->getObject($relation->target));

        return ForeignKeyConstraint::editor()
            ->setUnquotedName($this->identifier(\sprintf('fk_%s_%s', $object->tableName, $field->columnName)))
            ->setUnquotedReferencingColumnNames($this->nonEmpty($field->columnName))
            ->setUnquotedReferencedTableName($this->nonEmpty($target->tableName), DataSchema::NAME)
            ->setUnquotedReferencedColumnNames('id')
            ->setOnDeleteAction(match ($relation->onDelete) {
                OnDelete::SetNull => ReferentialAction::SET_NULL,
                OnDelete::Restrict => ReferentialAction::RESTRICT,
                OnDelete::Cascade => ReferentialAction::CASCADE,
            })
            ->create();
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
     * "pkey", and "<kind>:<field id>" for the index, unique index or foreign key of a field.
     * Two definitions of one table (before and after a rename) pair up by key.
     *
     * @return array{indexes: array<string, non-empty-string>, constraints: array<string, non-empty-string>}
     */
    public function objectNames(ObjectDefinitionDto $object): array
    {
        $names = ['indexes' => [], 'constraints' => ['pkey' => $this->identifier($object->tableName.'_pkey')]];
        foreach ([...array_values($object->fields), ...array_values($object->archivedFields)] as $field) {
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

    private function column(string $name, string $type, bool $notNull = false): Column
    {
        return Column::editor()
            ->setUnquotedName($this->nonEmpty($name))
            ->setTypeName($type)
            ->setNotNull($notNull)
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
     * Keeps readable names when they fit, otherwise shortens them with a stable hash.
     *
     * @return non-empty-string
     */
    public function identifier(string $name): string
    {
        if (\strlen($name) <= self::MAX_IDENTIFIER_LENGTH) {
            return $this->nonEmpty($name);
        }

        return substr($name, 0, self::MAX_IDENTIFIER_LENGTH - 9).'_'.substr(hash('sha256', $name), 0, 8);
    }

    /**
     * @return non-empty-string
     */
    private function nonEmpty(string $name): string
    {
        return '' !== $name ? $name : throw new \LogicException('Identifiers cannot be empty.');
    }
}
