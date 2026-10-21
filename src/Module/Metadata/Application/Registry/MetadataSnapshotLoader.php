<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Registry;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Domain\Model\FieldDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;

/**
 * Builds the full metadata snapshot from storage, without any caching.
 */
final class MetadataSnapshotLoader
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
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
            $field->getSettings(),
            $field->getPosition(),
            $field->isSystem(),
        );
    }
}
