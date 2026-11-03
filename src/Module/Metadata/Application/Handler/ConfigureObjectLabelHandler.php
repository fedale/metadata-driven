<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\ConfigureObjectLabelCommand;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Chooses the field that names the records of an object (relations, lookups, links).
 */
final class ConfigureObjectLabelHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly FieldTypeCatalogInterface $fieldTypes,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function __invoke(ConfigureObjectLabelCommand $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);

            $field = $command->field;
            if (null !== $field && $object->hasField($field) && $this->fieldTypes->isWriteOnly($object->getField($field)->getType())) {
                throw InvalidMetadataDefinition::writeOnlyField($field, 'the label of the records');
            }
            if (null !== $field && $object->hasField($field) && $object->getField($field)->isManyToMany()) {
                throw InvalidMetadataDefinition::manyToManyField($field, 'the label of the records');
            }
            $object->useAsLabel($field);
            $this->objects->save($object);
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));
    }
}
