<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Relation;

use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Message\Message;

/**
 * Checks a cascade (dependsOn / dependsVia of a relation field), when the field is created and
 * when its settings change.
 */
final class RelationDependencyChecker
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
    ) {
    }

    /**
     * dependsOn is another relation field of this object, and dependsVia a relation field of the
     * target pointing at the same object as dependsOn.
     *
     * @throws InvalidMetadataDefinition
     */
    public function assertValid(ObjectDefinition $object, string $fieldName, RelationSettings $relation): void
    {
        if (null === $relation->dependsOn || null === $relation->dependsVia) {
            return;
        }
        $parent = $object->hasField($relation->dependsOn) && $relation->dependsOn !== $fieldName ? $object->getField($relation->dependsOn) : null;
        $parentTarget = null !== $parent && RelationSettings::FIELD_TYPE === $parent->getType()
            ? RelationSettings::fromSettings($parent->getSettings())->target
            : null;
        if (null === $parentTarget) {
            throw InvalidMetadataDefinition::invalidDependency($fieldName, new Message('metadata.dependency.not_a_relation', '"%field%" is not a relation field of "%object%" (create it first)', ['field' => (string) $relation->dependsOn, 'object' => $object->getName()]));
        }

        $target = $this->objects->findByName($relation->target);
        $via = null !== $target && $target->hasField($relation->dependsVia) ? $target->getField($relation->dependsVia) : null;
        $viaTarget = null !== $via && RelationSettings::FIELD_TYPE === $via->getType()
            ? RelationSettings::fromSettings($via->getSettings())->target
            : null;
        if ($viaTarget !== $parentTarget) {
            throw InvalidMetadataDefinition::invalidDependency($fieldName, new Message('metadata.dependency.not_pointing_at', '"%field%" is not a relation field of "%object%" pointing at "%target%"', ['field' => (string) $relation->dependsVia, 'object' => $relation->target, 'target' => (string) $parentTarget]));
        }
    }
}
