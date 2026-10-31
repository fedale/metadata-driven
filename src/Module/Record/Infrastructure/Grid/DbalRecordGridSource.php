<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Grid;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Application\RecordLabels;
use App\Module\Record\Infrastructure\Dbal\RecordTable;
use App\Module\Search\Contract\Dto\SearchMatch;
use App\Module\Search\Contract\SearchConfigurationInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;

/**
 * Runs a grid query on an object table with DBAL.
 *
 * Every attribute is resolved against the object definition (whitelist) and quoted:
 * nothing from the request reaches the SQL as an identifier.
 */
final class DbalRecordGridSource implements RecordGridSourceInterface
{
    /** System columns a grid may sort on, besides the fields. */
    private const array SORTABLE_SYSTEM_COLUMNS = ['created_at', 'updated_at'];
    /** Alias of the object table; related tables are joined as r0, r1... */
    private const string ALIAS = 't';

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordTable $table,
        private readonly SearchConfigurationInterface $search,
        private readonly RecordLabels $labels,
    ) {
    }

    public function count(GridQuery $query): int
    {
        $object = $this->object($query);

        $count = $this->filtered($object, $query)->select('COUNT(*)')->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function fetch(GridQuery $query): iterable
    {
        $object = $this->object($query);
        $qb = $this->filtered($object, $query)
            ->select($this->table->selectList($object, self::ALIAS))
            ->setFirstResult($query->offset)
            ->setMaxResults($query->limit);

        // Each relation brings the label of the related record ("<field>__label").
        $labels = [];
        foreach ($this->relationJoins($object) as $fieldName => [$alias, $target, $labelField]) {
            $qb->leftJoin($this->table->quote(self::ALIAS), $this->table->table($target), $this->table->quote($alias), \sprintf(
                '%s = %s AND %s IS NULL',
                $this->table->column('id', $alias),
                $this->table->column($object->field($fieldName)->columnName, self::ALIAS),
                $this->table->column('deleted_at', $alias),
            ));
            if (null !== $labelField) {
                $labels[$fieldName] = $this->table->asText($labelField, $alias);
                $qb->addSelect($labels[$fieldName].' AS '.$this->table->quote($fieldName.self::LABEL_SUFFIX));
            }
        }

        foreach ($query->sort as $order) {
            $qb->addOrderBy(
                $labels[$order->attribute] ?? $this->sortColumn($object, $order->attribute),
                SortDirection::Desc === $order->direction ? 'DESC' : 'ASC',
            );
        }
        if ([] === $query->sort) {
            $qb->addOrderBy($this->table->column('created_at', self::ALIAS), 'DESC');
        }
        // A unique tie-breaker keeps the pages stable whatever the user sorts on.
        $qb->addOrderBy($this->table->column('id', self::ALIAS), 'DESC');

        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            $record = $this->table->hydrate($object, $row);
            $values = ['id' => $record->id] + $record->values;
            foreach (array_keys($labels) as $fieldName) {
                $label = $row[$fieldName.self::LABEL_SUFFIX] ?? null;
                $values[$fieldName.self::LABEL_SUFFIX] = \is_string($label) ? $label : null;
            }

            yield $values;
        }
    }

    /**
     * @return array<string, array{string, ObjectDefinitionDto, FieldDefinitionDto|null}> field name => [alias, target, label field]
     */
    private function relationJoins(ObjectDefinitionDto $object): array
    {
        $joins = [];
        foreach ($object->fields as $name => $field) {
            $relation = $field->relation();
            if (null !== $relation) {
                $target = $this->metadata->getObject($relation->target);
                $joins[$name] = ['r'.\count($joins), $target, $this->labels->fieldFor($target)];
            }
        }

        return $joins;
    }

    private function object(GridQuery $query): ObjectDefinitionDto
    {
        $name = \is_array($query->source) ? ($query->source['object'] ?? null) : null;
        if (!\is_string($name)) {
            throw new \InvalidArgumentException('The record grid source expects dataConfig()["model"] = ["object" => "<name>"].');
        }

        return $this->metadata->getObject($name);
    }

    private function filtered(ObjectDefinitionDto $object, GridQuery $query): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->from($this->table->table($object), $this->table->quote(self::ALIAS))
            ->where($this->table->column('deleted_at', self::ALIAS).' IS NULL');

        $virtual = $this->virtualColumns($object, $query);
        foreach ($query->criteria as $index => $criterion) {
            if (isset($virtual[$criterion->attribute])) {
                // The text of a virtual column: its fields joined by spaces (CONCAT_WS skips the
                // empty ones), close to how it reads.
                $this->applyTextCriterion($qb, \sprintf("CONCAT_WS(' ', %s)", implode(', ', $virtual[$criterion->attribute])), $criterion, 'c'.$index);
                continue;
            }
            $this->applyCriterion($qb, $this->readableField($object, $criterion->attribute), $criterion, 'c'.$index);
        }

        $term = null !== $query->searchTerm ? trim($query->searchTerm) : '';
        if ('' !== $term && [] !== $query->searchFields) {
            $this->applySearch($qb, $object, $term, $query->searchFields);
        }

        return $qb;
    }

    /**
     * The object's search handler turns the term into clauses: AND-ed, each one matching
     * any of its fields. LOWER(column) is the expression the trigram indexes are built on.
     *
     * @param list<string> $fields
     */
    private function applySearch(QueryBuilder $qb, ObjectDefinitionDto $object, string $term, array $fields): void
    {
        foreach ($this->search->clauses($object, $term, $fields) as $index => $clause) {
            $param = 'q'.$index;
            $matches = [];
            foreach ($clause->fields as $attribute) {
                $matches[] = \sprintf('LOWER(%s) LIKE :%s', $this->table->asText($this->readableField($object, $attribute), self::ALIAS), $param);
            }
            $pattern = RecordTable::escapeLike($clause->value).'%';
            $qb->andWhere('('.implode(' OR ', $matches).')')
                ->setParameter($param, SearchMatch::Contains === $clause->match ? '%'.$pattern : $pattern);
        }
    }

    /**
     * The virtual columns of the grid view (GridQuery::$source["virtual"]): name => the text
     * expressions of their fields, each resolved against the object like any attribute.
     *
     * @return array<string, list<string>>
     */
    private function virtualColumns(ObjectDefinitionDto $object, GridQuery $query): array
    {
        $virtual = [];
        $declared = \is_array($query->source) && \is_array($query->source['virtual'] ?? null) ? $query->source['virtual'] : [];
        foreach ($declared as $name => $fields) {
            if (\is_string($name) && \is_array($fields) && [] !== $fields) {
                $virtual[$name] = array_values(array_map(
                    fn (mixed $field): string => $this->table->asText($this->readableField($object, \is_string($field) ? $field : ''), self::ALIAS),
                    $fields,
                ));
            }
        }

        return $virtual;
    }

    private function applyCriterion(QueryBuilder $qb, FieldDefinitionDto $field, FilterCriterion $criterion, string $param): void
    {
        if ($this->isText($criterion)) {
            // Text comparison: the column (cast to text when needed) against a string.
            $this->applyTextCriterion($qb, $this->table->asText($field, self::ALIAS), $criterion, $param);

            return;
        }

        $column = $this->table->column($field->columnName, self::ALIAS);
        $type = $this->table->typeOf($field);
        // Numbers come as int or float: compare as NUMERIC, so "age > 3.5" is not truncated to 3.
        if (\in_array($type, [Types::INTEGER, Types::DECIMAL], true)) {
            $value = \is_array($criterion->value) ? array_map($this->string(...), $criterion->value) : $this->string($criterion->value);
            $this->compare($qb, $column, $criterion->operator, $param, $value, 'CAST(:%s AS NUMERIC)', Types::STRING);

            return;
        }

        $this->compare($qb, $column, $criterion->operator, $param, $criterion->value, ':%s', $type);
    }

    private function isText(FilterCriterion $criterion): bool
    {
        return $criterion->caseInsensitive || null !== $this->likePattern($criterion->operator);
    }

    /**
     * @return array{string, string}|null the wildcards before and after the term; null: no LIKE
     */
    private function likePattern(FilterOperator $operator): ?array
    {
        return match ($operator) {
            FilterOperator::Contains, FilterOperator::NotContains => ['%', '%'],
            FilterOperator::StartsWith => ['', '%'],
            FilterOperator::EndsWith => ['%', ''],
            default => null,
        };
    }

    /**
     * @param string $column an SQL text expression, built from whitelisted columns only
     */
    private function applyTextCriterion(QueryBuilder $qb, string $column, FilterCriterion $criterion, string $param): void
    {
        $operator = $criterion->operator;
        $like = $this->likePattern($operator);
        $value = $criterion->value;
        if ($criterion->caseInsensitive) {
            $column = 'LOWER('.$column.')';
            $value = \is_array($value) ? array_map($this->lower(...), $value) : $this->lower($value);
        }
        if (null !== $like) {
            $not = FilterOperator::NotContains === $operator ? 'NOT ' : '';
            $qb->andWhere(\sprintf('%s %sLIKE :%s', $column, $not, $param))
                ->setParameter($param, $like[0].RecordTable::escapeLike($this->string($value)).$like[1]);

            return;
        }
        $this->compare($qb, $column, $operator, $param, $value, ':%s', Types::STRING);
    }

    /**
     * @param string $placeholder sprintf pattern wrapping the parameter name, e.g. ":%s"
     */
    private function compare(QueryBuilder $qb, string $column, FilterOperator $operator, string $param, mixed $value, string $placeholder, string $type): void
    {
        $sqlOperator = match ($operator) {
            FilterOperator::Eq => '=',
            FilterOperator::Neq => '<>',
            FilterOperator::Lt => '<',
            FilterOperator::Lte => '<=',
            FilterOperator::Gt => '>',
            FilterOperator::Gte => '>=',
            default => null,
        };

        if (null !== $sqlOperator) {
            $qb->andWhere(\sprintf('%s %s %s', $column, $sqlOperator, \sprintf($placeholder, $param)))
                ->setParameter($param, $value, $type);

            return;
        }

        if (FilterOperator::In === $operator && \is_array($value)) {
            $qb->andWhere(\sprintf('%s IN (:%s)', $column, $param))
                ->setParameter($param, array_map($this->string(...), $value), ArrayParameterType::STRING);

            return;
        }

        if (FilterOperator::Between === $operator && \is_array($value) && 2 === \count($value)) {
            [$low, $high] = array_values($value);
            $qb->andWhere(\sprintf('%s BETWEEN %s AND %s', $column, \sprintf($placeholder, $param.'_low'), \sprintf($placeholder, $param.'_high')))
                ->setParameter($param.'_low', $low, $type)
                ->setParameter($param.'_high', $high, $type);

            return;
        }

        throw new \InvalidArgumentException(\sprintf('Unsupported filter operator "%s" for column %s.', $operator->value, $column));
    }

    private function sortColumn(ObjectDefinitionDto $object, string $attribute): string
    {
        if (\in_array($attribute, self::SORTABLE_SYSTEM_COLUMNS, true)) {
            return $this->table->column($attribute, self::ALIAS);
        }

        return $this->table->column($this->readableField($object, $attribute)->columnName, self::ALIAS);
    }

    /**
     * A field the grid may filter, search or sort on: a write-only one (a password hash)
     * would leak through the order or a LIKE, so it is refused like an unknown field.
     */
    private function readableField(ObjectDefinitionDto $object, string $attribute): FieldDefinitionDto
    {
        $field = $object->field($attribute);
        if ($this->table->isWriteOnly($field)) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" of "%s" cannot be read.', $attribute, $object->name));
        }

        return $field;
    }

    private function lower(mixed $value): string
    {
        return mb_strtolower($this->string($value));
    }

    private function string(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? 'true' : 'false',
            default => throw new \InvalidArgumentException(\sprintf('Unsupported filter value of type %s.', get_debug_type($value))),
        };
    }
}
