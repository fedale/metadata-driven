<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Registry;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
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
        );
    }
}
