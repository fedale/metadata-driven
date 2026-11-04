<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\ConfigureObjectSearchCommand;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Event\ObjectSearchConfigured;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Contract\SearchHandlerCatalogInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

final class ConfigureObjectSearchHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly SearchHandlerCatalogInterface $searchHandlers,
        private readonly FieldTypeCatalogInterface $fieldTypes,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function __invoke(ConfigureObjectSearchCommand $command): void
    {
        if (null !== $command->handler && !$this->searchHandlers->supports($command->handler)) {
            throw InvalidMetadataDefinition::unknownSearchHandler($command->handler, $this->searchHandlers->names());
        }

        $this->transactions->run(function () use ($command): void {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);

            foreach ($command->fields ?? [] as $field) {
                if ($object->hasField($field) && $this->fieldTypes->isWriteOnly($object->getField($field)->getType())) {
                    throw InvalidMetadataDefinition::writeOnlyField($field, 'searched');
                }
                if ($object->hasField($field) && $object->getField($field)->isManyToMany()) {
                    throw InvalidMetadataDefinition::manyToManyField($field, 'searched');
                }
                if ($object->hasField($field) && !$this->fieldTypes->isSortable($type = $object->getField($field)->getType())) {
                    throw InvalidMetadataDefinition::noTextValue($field, $type, 'searched');
                }
            }
            $object->configureSearch($command->handler, $command->fields);
            $this->objects->save($object);

            $this->events->dispatch(new ObjectSearchConfigured($object->getId()->toRfc4122(), $object->getName()));
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));
    }
}
