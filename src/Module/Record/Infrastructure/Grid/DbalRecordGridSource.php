<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Grid;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\File\Contract\FileStorageInterface;
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
use Symfony\Component\Uid\Uuid;

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
    /** Aliases of a link table and of its linked object's table, inside a subquery. */
    private const string LINK_ALIAS = 'l';
    private const string LINKED_ALIAS = 'x';
    /** Of the SQL column carrying the linked ids of a many-to-many field ("<field>__ids"). */
    private const string LINK_IDS_SUFFIX = '__ids';
    /** Between the labels of the linked records in a cell. */
    private const string LINK_LABEL_SEPARATOR = ', ';

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordTable $table,
        private readonly SearchConfigurationInterface $search,
        private readonly RecordLabels $labels,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly FileStorageInterface $files,
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

        // Each many-to-many field brings the labels of the linked records, joined, in the order
        // of the labels, and their ids in the same order.
        $linkFields = [];
        foreach ($object->fields as $fieldName => $field) {
            if (null !== $field->manyToMany()) {
                [$labelList, $idList] = $this->linkLists($object, $field);
                $qb->addSelect($labelList.' AS '.$this->table->quote($fieldName.self::LABEL_SUFFIX))
                    ->addSelect($idList.' AS '.$this->table->quote($fieldName.self::LINK_IDS_SUFFIX));
                $linkFields[] = $fieldName;
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

        $page = [];
        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            $links = [];
            foreach ($linkFields as $fieldName) {
                $ids = $row[$fieldName.self::LINK_IDS_SUFFIX] ?? null;
                $links[$fieldName] = \is_string($ids) && '' !== $ids ? explode(',', $ids) : [];
            }
            $record = $this->table->hydrate($object, $row, $links);
            $values = ['id' => $record->id] + $record->values;
            foreach ([...array_keys($labels), ...$linkFields] as $fieldName) {
                $label = $row[$fieldName.self::LABEL_SUFFIX] ?? null;
                $values[$fieldName.self::LABEL_SUFFIX] = \is_string($label) ? $label : null;
            }
            $page[] = $values;
        }

        yield from $this->withFiles($object, $page);
    }

    /**
     * The files of a page of rows, looked up in one query through the File module, which owns
     * core.file: under "<field>__file" and their names under "<field>__label".
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withFiles(ObjectDefinitionDto $object, array $rows): array
    {
        $fileFields = array_keys(array_filter($object->fields, $this->isFile(...)));
        if ([] === $fileFields || [] === $rows) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $row) {
            foreach ($fileFields as $fieldName) {
                if (\is_string($row[$fieldName] ?? null)) {
                    $ids[] = $row[$fieldName];
                }
            }
        }
        $files = $this->files->findMany(array_values(array_unique($ids)));

        foreach ($rows as $index => $row) {
            foreach ($fileFields as $fieldName) {
                $id = $row[$fieldName] ?? null;
                $file = \is_string($id) ? ($files[strtolower($id)] ?? null) : null;
                $held = null === $file ? [] : [['id' => $file->id, 'name' => $file->originalName, 'image' => $file->isImage()]];
                $rows[$index][$fieldName.self::FILE_SUFFIX] = $held;
                $rows[$index][$fieldName.self::LABEL_SUFFIX] = [] === $held ? null : implode(self::LINK_LABEL_SEPARATOR, array_column($held, 'name'));
            }
        }

        return $rows;
    }

    private function isFile(FieldDefinitionDto $field): bool
    {
        return WidgetKind::File === $this->fieldTypes->get($field->type)->widget($field->settings)->kind;
    }

    /**
     * The correlated subqueries listing the live records linked to the row by a many-to-many
     * field: their labels joined by ", " and their ids joined by ",", both in the order of the
     * labels (then of the ids); NULL when there are none. A record whose label is empty is named
     * as RecordLabels::fallback() names it.
     *
     * @return array{string, string} [labels, ids]
     */
    private function linkLists(ObjectDefinitionDto $object, FieldDefinitionDto $field): array
    {
        $target = $this->metadata->getObject((string) $field->target());
        $labelField = $this->labels->fieldFor($target);
        $id = $this->table->column('id', self::LINKED_ALIAS);
        $fallback = \sprintf("'#' || LEFT(CAST(%s AS TEXT), 8)", $id);
        $label = null === $labelField
            ? $fallback
            : \sprintf("COALESCE(NULLIF(%s, ''), %s)", $this->table->asText($labelField, self::LINKED_ALIAS), $fallback);
        $from = $this->linkedFrom($object, $field);
        $order = \sprintf('ORDER BY %s, %s', $label, $id);

        return [
            \sprintf('(SELECT STRING_AGG(%s, %s %s) %s)', $label, $this->connection->quote(self::LINK_LABEL_SEPARATOR), $order, $from),
            \sprintf("(SELECT STRING_AGG(CAST(%s AS TEXT), ',' %s) %s)", $id, $order, $from),
        ];
    }

    /**
     * FROM and WHERE of a subquery on the live records linked to the current row of the object
     * table by a many-to-many field; the link table is aliased "l", the linked records "x".
     */
    private function linkedFrom(ObjectDefinitionDto $object, FieldDefinitionDto $field): string
    {
        $links = $this->table->links($object, $field);

        return \sprintf(
            'FROM %1$s %2$s JOIN %3$s %4$s ON %5$s = %2$s.%6$s AND %7$s IS NULL WHERE %2$s.%8$s = %9$s%10$s',
            $links->table,
            $this->table->quote(self::LINK_ALIAS),
            $links->targetTable,
            $this->table->quote(self::LINKED_ALIAS),
            $this->table->column('id', self::LINKED_ALIAS),
            $links->targetColumn,
            $this->table->column('deleted_at', self::LINKED_ALIAS),
            $links->recordColumn,
            $this->table->column('id', self::ALIAS),
            $links->andLive($this->table->quote(self::LINK_ALIAS)),
        );
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
            $field = $this->readableField($object, $criterion->attribute);
            if (null !== $field->manyToMany()) {
                $this->applyLinkCriterion($qb, $object, $field, $criterion, 'c'.$index);
                continue;
            }
            if ($this->isFile($field)) {
                $this->applyFileCriterion($qb, $object, $field, $criterion);
                continue;
            }
            $this->applyCriterion($qb, $field, $criterion, 'c'.$index);
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
                $matches[] = \sprintf('LOWER(%s) LIKE :%s', $this->table->asText($this->columnField($object, $attribute), self::ALIAS), $param);
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
                    fn (mixed $field): string => $this->table->asText($this->columnField($object, \is_string($field) ? $field : ''), self::ALIAS),
                    $fields,
                ));
            }
        }

        return $virtual;
    }

    /**
     * A many-to-many field matches the records linked to any of the given ids (the relation
     * filter sends one id, or a list of them). A value that is no id matches nothing.
     */
    private function applyLinkCriterion(QueryBuilder $qb, ObjectDefinitionDto $object, FieldDefinitionDto $field, FilterCriterion $criterion, string $param): void
    {
        if (!\in_array($criterion->operator, [FilterOperator::Eq, FilterOperator::In], true)) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" of "%s" is many-to-many: it is filtered by the records it links to, not with "%s".', $field->name, $object->name, $criterion->operator->value));
        }
        $ids = [];
        foreach (\is_array($criterion->value) ? $criterion->value : [$criterion->value] as $value) {
            if (\is_string($value) && Uuid::isValid(trim($value))) {
                $ids[] = strtolower(trim($value));
            }
        }
        if ([] === $ids) {
            $qb->andWhere('1 = 0');

            return;
        }
        $links = $this->table->links($object, $field);
        $qb->andWhere(\sprintf(
            'EXISTS (SELECT 1 %s AND %s.%s IN (:%s))',
            $this->linkedFrom($object, $field),
            $this->table->quote(self::LINK_ALIAS),
            $links->targetColumn,
            $param,
        ))->setParameter($param, array_values(array_unique($ids)), ArrayParameterType::STRING);
    }

    /**
     * A file field matches the records that hold a file (true) or hold none (false): its column
     * is the id of a file, with nothing to compare but its presence.
     */
    private function applyFileCriterion(QueryBuilder $qb, ObjectDefinitionDto $object, FieldDefinitionDto $field, FilterCriterion $criterion): void
    {
        if (FilterOperator::Eq !== $criterion->operator || !\is_bool($criterion->value)) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" of "%s" is a file: it is filtered on whether it holds one (true or false), not with "%s".', $field->name, $object->name, $criterion->operator->value));
        }
        // Its files are rows of a link table, read by the grid from phase 7 of the plan on.
        if (!$field->storesColumn()) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" of "%s" holds several files: the grid does not filter it yet.', $field->name, $object->name));
        }
        $qb->andWhere(\sprintf('%s IS %sNULL', $this->table->column($field->columnName, self::ALIAS), $criterion->value ? 'NOT ' : ''));
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

        return $this->table->column($this->columnField($object, $attribute)->columnName, self::ALIAS);
    }

    /**
     * A readable field with a column of its own, for a search, a virtual column or a sort: a
     * many-to-many field has a set of linked records instead, with no single text or order; the
     * column of a file field holds an id, whose text and order mean nothing.
     */
    private function columnField(ObjectDefinitionDto $object, string $attribute): FieldDefinitionDto
    {
        $field = $this->readableField($object, $attribute);
        if (!$this->fieldTypes->get($field->type)->isSortable()) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" of "%s" is %s: it cannot be searched, sorted on or joined in a virtual column.', $attribute, $object->name, null === $field->manyToMany() ? 'a '.$field->type : 'many-to-many'));
        }

        return $field;
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
