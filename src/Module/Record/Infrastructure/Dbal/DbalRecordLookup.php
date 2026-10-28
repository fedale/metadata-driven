<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\RecordLabels;
use App\Module\Record\Contract\Dto\RecordLabel;
use App\Module\Record\Contract\RecordLookupInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

final class DbalRecordLookup implements RecordLookupInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordTable $table,
        private readonly RecordLabels $labels,
    ) {
    }

    public function search(string $objectName, string $term, int $limit = 20, array $filters = []): array
    {
        $object = $this->metadata->getObject($objectName);
        [$qb, $label] = $this->query($object);

        $index = 0;
        foreach ($filters as $fieldName => $id) {
            // Whitelisted: only a relation field of the object, compared as a uuid.
            if (!$object->hasField($fieldName) || null === $object->field($fieldName)->relation()) {
                throw new \InvalidArgumentException(\sprintf('Object "%s" has no relation field "%s" to filter on.', $object->name, $fieldName));
            }
            $param = 'filter'.$index++;
            $qb->andWhere(\sprintf('%s = :%s', $this->table->column($object->field($fieldName)->columnName), $param))
                ->setParameter($param, $id, Types::GUID);
        }

        $term = trim($term);
        if ('' !== $term && null !== $label) {
            $qb->andWhere(\sprintf('LOWER(%s) LIKE :term', $label))
                ->setParameter('term', '%'.RecordTable::escapeLike(mb_strtolower($term)).'%');
        }
        if (null !== $label) {
            $qb->orderBy($label, 'ASC');
        }
        $qb->addOrderBy($this->table->column('id'), 'ASC')->setMaxResults(max(1, $limit));

        return array_map($this->toLabel(...), $qb->executeQuery()->fetchAllAssociative());
    }

    public function labels(string $objectName, array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => Uuid::isValid($id)));
        if ([] === $ids) {
            return [];
        }

        [$qb] = $this->query($this->metadata->getObject($objectName));
        $qb->andWhere(\sprintf('%s IN (:ids)', $this->table->column('id')))
            ->setParameter('ids', array_map(strtolower(...), $ids), ArrayParameterType::STRING);

        $labels = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $record = $this->toLabel($row);
            $labels[$record->id] = $record->label;
        }

        return $labels;
    }

    /**
     * @return array{QueryBuilder, string|null} the query on live records, and the label expression
     */
    private function query(ObjectDefinitionDto $object): array
    {
        $labelField = $this->labels->fieldFor($object);
        $label = null !== $labelField ? $this->table->asText($labelField) : null;

        $qb = $this->connection->createQueryBuilder()
            ->select($this->table->column('id').', '.($label ?? 'NULL').' AS label')
            ->from($this->table->table($object))
            ->where($this->table->column('deleted_at').' IS NULL');

        return [$qb, $label];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toLabel(array $row): RecordLabel
    {
        $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
        $label = \is_string($row['label'] ?? null) && '' !== trim($row['label']) ? $row['label'] : RecordLabels::fallback($id);

        return new RecordLabel($id, $label);
    }
}
