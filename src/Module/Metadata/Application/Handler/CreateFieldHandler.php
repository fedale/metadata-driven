<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Relation\RelationDependencyChecker;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Event\FieldDefinitionCreated;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Model\MachineName;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class CreateFieldHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly FieldTypeCatalogInterface $fieldTypes,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
        private readonly MetadataRegistryInterface $metadata,
        private readonly RelationDependencyChecker $dependencies,
    ) {
    }

    /**
     * @return string the id of the new field definition
     */
    public function __invoke(CreateFieldCommand $command): string
    {
        if (!$this->fieldTypes->supports($command->type)) {
            throw InvalidMetadataDefinition::unknownFieldType($command->type, $this->fieldTypes->names());
        }
        $settings = $this->fieldTypes->resolveSettings($command->type, $command->settings);
        if ($command->isUnique && $this->fieldTypes->isWriteOnly($command->type)) {
            throw InvalidMetadataDefinition::writeOnlyField($command->name, 'unique');
        }
        if ($command->isUnique && !$this->fieldTypes->supportsUnique($command->type)) {
            throw InvalidMetadataDefinition::notUniqueType($command->name, $command->type);
        }

        $fieldId = $this->transactions->run(function () use ($command, $settings): string {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);
            if (RelationSettings::FIELD_TYPE === $command->type) {
                $relation = RelationSettings::fromSettings($settings);
                $this->assertRelationIsValid($relation);
                $this->dependencies->assertValid($object, $command->name, $relation);
            }

            $field = $object->addField(
                Uuid::v7(),
                $command->name,
                $command->type,
                $command->label,
                $command->isRequired,
                $command->isUnique,
                $settings,
            );
            $this->objects->save($object);

            $fieldId = $field->getId()->toRfc4122();
            $this->events->dispatch(new FieldDefinitionCreated(
                $object->getId()->toRfc4122(),
                $object->getName(),
                $fieldId,
                $field->getName(),
            ));

            return $fieldId;
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));

        return $fieldId;
    }

    /**
     * The target must exist, and the inverse name must be free on it: neither one of its
     * fields nor the inverse side of another relation pointing at it.
     */
    private function assertRelationIsValid(RelationSettings $relation): void
    {
        $target = $this->objects->findByName($relation->target)
            ?? throw InvalidMetadataDefinition::unknownRelationTarget($relation->target);

        if (null === $relation->inverseName) {
            return;
        }
        MachineName::assert($relation->inverseName);
        $taken = $target->hasField($relation->inverseName);
        foreach ($this->metadata->incomingRelations($target->getName()) as $reference) {
            $taken = $taken || $reference->settings->inverseName === $relation->inverseName;
        }
        if ($taken) {
            throw InvalidMetadataDefinition::inverseNameTaken($target->getName(), $relation->inverseName);
        }
    }
}
