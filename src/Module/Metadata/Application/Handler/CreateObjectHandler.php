<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Event\ObjectDefinitionCreated;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class CreateObjectHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * @return string the id of the new object definition
     */
    public function __invoke(CreateObjectCommand $command): string
    {
        $id = $this->transactions->run(function () use ($command): string {
            if (null !== $this->objects->findByName($command->name)) {
                throw InvalidMetadataDefinition::duplicateObject($command->name);
            }

            $object = new ObjectDefinition(Uuid::v7(), $command->name, $command->labelSingular, $command->labelPlural, $command->icon);
            $this->objects->save($object);

            $id = $object->getId()->toRfc4122();
            $this->events->dispatch(new ObjectDefinitionCreated($id, $object->getName()));

            return $id;
        });
        $this->events->dispatch(new MetadataChanged($command->name));

        return $id;
    }
}
