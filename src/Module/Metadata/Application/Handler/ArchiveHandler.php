<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Lifecycle\MetadataUsages;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Event\FieldArchiveChanged;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Takes objects and fields out of the application, and brings them back. Nothing physical
 * changes: the table, the columns and the records stay, and so do the translations and the
 * permissions, ready for a restore.
 *
 * Archiving is refused while the rest of the metadata uses the object or the field (a
 * relation, a form, a view, a condition, a cascade): every use is listed, to be removed first.
 */
final class ArchiveHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly MetadataRegistryInterface $metadata,
        private readonly MetadataUsages $usages,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function archiveObject(string $objectName): void
    {
        $this->transactions->run(function () use ($objectName): void {
            $object = $this->active($objectName);
            if ($object->isSystem()) {
                throw InvalidMetadataDefinition::systemObject($objectName, 'archived');
            }
            $usages = $this->usages->ofObject($objectName);
            if ([] !== $usages) {
                throw InvalidMetadataDefinition::stillUsed(\sprintf('Object "%s"', $objectName), 'archived', $usages);
            }
            $object->archive();
            $this->objects->save($object);
        });
        $this->events->dispatch(new MetadataChanged($objectName));
    }

    /**
     * The objects its relation fields point at must be active again first.
     */
    public function restoreObject(string $objectName): void
    {
        $this->transactions->run(function () use ($objectName): void {
            $object = $this->objects->findByName($objectName);
            if (null === $object || $object->isActive()) {
                throw InvalidMetadataDefinition::unknownArchivedObject($objectName);
            }
            $archived = $this->metadata->archivedObjects()[$objectName] ?? throw InvalidMetadataDefinition::unknownArchivedObject($objectName);
            $problems = [];
            foreach ($archived->fields as $field) {
                $target = $field->target();
                if (null !== $target && $target !== $objectName && !$this->metadata->hasObject($target)) {
                    $problems[] = \sprintf('%s field "%s" points at object "%s", archived too: restore it first', $field->storesColumn() ? 'relation' : 'many-to-many', $field->name, $target);
                }
            }
            if ([] !== $problems) {
                throw InvalidMetadataDefinition::cannotRestore(\sprintf('Object "%s"', $objectName), $problems);
            }
            $object->restore();
            $this->objects->save($object);
        });
        $this->events->dispatch(new MetadataChanged($objectName));
    }

    public function archiveField(string $objectName, string $fieldName): void
    {
        $this->transactions->run(function () use ($objectName, $fieldName): void {
            $object = $this->active($objectName);
            $object->getField($fieldName);
            $usages = $this->usages->ofField($objectName, $fieldName);
            if ([] !== $usages) {
                throw InvalidMetadataDefinition::stillUsed(\sprintf('Field "%s.%s"', $objectName, $fieldName), 'archived', $usages);
            }
            $object->archiveField($fieldName);
            $this->objects->save($object);
            $this->events->dispatch(new FieldArchiveChanged($objectName, $fieldName, true));
        });
        $this->events->dispatch(new MetadataChanged($objectName));
    }

    /**
     * What the field relies on must be active: the object its relation points at, the field
     * deciding whether it is shown, the fields of its cascade.
     */
    public function restoreField(string $objectName, string $fieldName): void
    {
        $this->transactions->run(function () use ($objectName, $fieldName): void {
            $object = $this->active($objectName);
            $object->getArchivedField($fieldName);
            $definition = $this->metadata->getObject($objectName);
            $field = $definition->archivedFields[$fieldName] ?? throw InvalidMetadataDefinition::unknownArchivedField($objectName, $fieldName);
            $problems = $this->restoreProblems($definition->fields, $field);
            if ([] !== $problems) {
                throw InvalidMetadataDefinition::cannotRestore(\sprintf('Field "%s.%s"', $objectName, $fieldName), $problems);
            }
            $object->restoreField($fieldName);
            $this->objects->save($object);
            $this->events->dispatch(new FieldArchiveChanged($objectName, $fieldName, false));
        });
        $this->events->dispatch(new MetadataChanged($objectName));
    }

    /**
     * @param array<string, FieldDefinitionDto> $active the active fields of its object
     *
     * @return list<string>
     */
    private function restoreProblems(array $active, FieldDefinitionDto $field): array
    {
        $problems = [];
        $deciding = $field->condition?->field;
        if (null !== $deciding && !isset($active[$deciding])) {
            $problems[] = \sprintf('it is shown depending on "%s", which is archived or gone: restore it, or restore the field after removing its condition', $deciding);
        }
        $target = $field->target();
        if (null !== $target && !$this->metadata->hasObject($target)) {
            $problems[] = \sprintf('it points at object "%s", which is archived: restore it first', $target);
        }
        $relation = $field->relation();
        if (null !== $relation?->dependsVia && $this->metadata->hasObject($relation->target)
            && !$this->metadata->getObject($relation->target)->hasField($relation->dependsVia)) {
            $problems[] = \sprintf('its cascade filters through "%s.%s", which is archived', $relation->target, $relation->dependsVia);
        }
        if (null !== $relation?->dependsOn && !isset($active[$relation->dependsOn])) {
            $problems[] = \sprintf('its cascade depends on "%s", which is archived', $relation->dependsOn);
        }

        return $problems;
    }

    private function active(string $objectName): ObjectDefinition
    {
        $object = $this->objects->findByName($objectName);
        if (null === $object || !$object->isActive()) {
            throw ObjectDefinitionNotFound::named($objectName);
        }

        return $object;
    }
}
