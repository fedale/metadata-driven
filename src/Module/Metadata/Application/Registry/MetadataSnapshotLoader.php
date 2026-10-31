<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Registry;

use App\Module\Metadata\Contract\Dto\FieldConditionDto;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\GridColumnDto;
use App\Module\Metadata\Contract\Dto\GridViewDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Domain\Model\FieldDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Model\ViewDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;

/**
 * Builds the full metadata snapshot from storage, without any caching.
 */
final class MetadataSnapshotLoader
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly FieldTypeCatalogInterface $fieldTypes,
    ) {
    }

    /**
     * @return array<string, ObjectDefinitionDto> keyed by object name
     */
    public function load(): array
    {
        $snapshot = [];
        foreach ($this->objects->findAll() as $object) {
            $snapshot[$object->getName()] = $this->mapObject($object);
        }

        return $snapshot;
    }

    private function mapObject(ObjectDefinition $object): ObjectDefinitionDto
    {
        $fields = [];
        foreach ($object->getFields() as $field) {
            $fields[$field->getName()] = $this->mapField($field);
        }

        return new ObjectDefinitionDto(
            $object->getId()->toRfc4122(),
            $object->getName(),
            $object->getLabelSingular(),
            $object->getLabelPlural(),
            $object->getTableName(),
            $object->getIcon(),
            $object->isSystem(),
            $object->isActive(),
            $fields,
            $object->getSearchHandler(),
            $object->getSearchFields(),
            $object->getLabelField(),
            array_map($this->mapView(...), $object->getViews()),
        );
    }

    private function mapField(FieldDefinition $field): FieldDefinitionDto
    {
        return new FieldDefinitionDto(
            $field->getId()->toRfc4122(),
            $field->getName(),
            $field->getColumnName(),
            $field->getType(),
            $field->getLabel(),
            $field->isRequired(),
            $field->isUnique(),
            // A setting added to a type after the field was created takes its default.
            $field->getSettings() + $this->fieldTypes->defaultSettings($field->getType()),
            $field->getPosition(),
            $field->isSystem(),
            null === $field->getCondition() ? null : new FieldConditionDto($field->getCondition()['field'], $field->getCondition()['values']),
        );
    }

    private function mapView(ViewDefinition $view): ViewDefinitionDto
    {
        return new ViewDefinitionDto(
            $view->getId()->toRfc4122(),
            $view->getKind(),
            $view->getName(),
            $view->getTemplate(),
            $view->isDefault(),
            null === $view->getDefinition() ? null : self::mapGrid($view->getDefinition()),
        );
    }

    /**
     * @param array<string, mixed> $definition as compiled by GridViewCompiler
     */
    private static function mapGrid(array $definition): GridViewDto
    {
        $columns = [];
        foreach (\is_array($definition['columns'] ?? null) ? $definition['columns'] : [] as $column) {
            if (!\is_array($column) || !\is_string($column['key'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $options compiled: a map */
            $options = \is_array($column['options'] ?? null) ? $column['options'] : [];
            $columns[] = new GridColumnDto(
                $column['key'],
                \is_string($column['field'] ?? null) ? $column['field'] : null,
                \is_string($column['template'] ?? null) ? $column['template'] : null,
                \is_array($column['sources'] ?? null) ? array_values(array_filter($column['sources'], is_string(...))) : [],
                \is_string($column['label'] ?? null) ? $column['label'] : null,
                \is_string($column['renderer'] ?? null) ? $column['renderer'] : null,
                $options,
                true === ($column['hidden'] ?? false),
                false !== ($column['sortable'] ?? true),
                false !== ($column['filter'] ?? true),
            );
        }
        $sort = [];
        foreach (\is_array($definition['sort'] ?? null) ? $definition['sort'] : [] as $key => $direction) {
            if (\is_string($key) && \in_array($direction, ['asc', 'desc'], true)) {
                $sort[$key] = $direction;
            }
        }

        return new GridViewDto($columns, $sort, \is_int($definition['pageSize'] ?? null) ? $definition['pageSize'] : null);
    }
}
