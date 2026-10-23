<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\DeleteViewCommand;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

final class DeleteViewHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function __invoke(DeleteViewCommand $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);

            $object->removeView($command->kind, $command->name);
            $this->objects->save($object);
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));
    }
}
