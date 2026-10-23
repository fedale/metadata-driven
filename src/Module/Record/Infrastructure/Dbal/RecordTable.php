<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Shared\Database\DataSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * SQL vocabulary of an object table: quoted identifiers, DBAL binding types, hydration.
 *
 * Identifiers always come from the metadata registry, never from user input, and are quoted.
 */
final class RecordTable
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FieldTypeRegistryInterface $fieldTypes,
    ) {
    }

    public function table(ObjectDefinitionDto $object): string
    {
        return $this->quote(DataSchema::NAME).'.'.$this->quote($object->tableName);
    }

    public function quote(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }

    /**
     * DBAL type used to bind and read the column of a field.
     */
    public function typeOf(FieldDefinitionDto $field): string
    {
        return $this->fieldTypes->get($field->type)->columnSpec($field->settings)->type->value;
    }

    /**
     * A write-only field (a password) is stored but never selected.
     */
    public function isWriteOnly(FieldDefinitionDto $field): bool
    {
        return $this->fieldTypes->get($field->type)->isWriteOnly();
    }

    /**
     * A quoted column, qualified by a table alias when given.
     */
    public function column(string $columnName, ?string $alias = null): string
    {
        return (null !== $alias ? $this->quote($alias).'.' : '').$this->quote($columnName);
    }

    /**
     * @return string comma separated, quoted list of the columns read by hydrate(), write-only ones left out
     */
    public function selectList(ObjectDefinitionDto $object, ?string $alias = null): string
    {
        $columns = ['id', 'created_at', 'updated_at'];
        foreach ($object->fields as $field) {
            if (!$this->isWriteOnly($field)) {
                $columns[] = $field->columnName;
            }
        }

        return implode(', ', array_map(fn (string $column): string => $this->column($column, $alias), $columns));
    }

    /**
     * The column as text, for LIKE and case-insensitive comparisons on any field type.
     * LOWER() of it, on a text column, is the expression the trigram indexes are built on.
     */
    public function asText(FieldDefinitionDto $field, ?string $alias = null): string
    {
        $column = $this->column($field->columnName, $alias);

        return \in_array($this->typeOf($field), [Types::STRING, Types::TEXT], true) ? $column : 'CAST('.$column.' AS TEXT)';
    }

    /**
     * Escapes the LIKE wildcards of a user term (PostgreSQL's default escape character is "\\").
     */
    public static function escapeLike(string $term): string
    {
        return addcslashes($term, '\\%_');
    }

    /**
     * @param array<string, mixed> $row as returned by a query on selectList()
     */
    public function hydrate(ObjectDefinitionDto $object, array $row): RecordOutput
    {
        $values = [];
        foreach ($object->fields as $name => $field) {
            if ($this->isWriteOnly($field)) {
                continue;
            }
            $values[$name] = $this->toPhp($row[$field->columnName] ?? null, $this->typeOf($field));
        }

        $id = $this->toPhp($row['id'] ?? null, Types::GUID);
        $createdAt = $this->toPhp($row['created_at'] ?? null, Types::DATETIME_IMMUTABLE);
        $updatedAt = $this->toPhp($row['updated_at'] ?? null, Types::DATETIME_IMMUTABLE);
        if (!\is_string($id) || !$createdAt instanceof \DateTimeImmutable || !$updatedAt instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException(\sprintf('Corrupted system columns in table "%s".', $object->tableName));
        }

        return new RecordOutput($id, $object->name, $values, $createdAt, $updatedAt);
    }

    private function toPhp(mixed $value, string $type): mixed
    {
        return Type::getType($type)->convertToPHPValue($value, $this->connection->getDatabasePlatform());
    }
}
