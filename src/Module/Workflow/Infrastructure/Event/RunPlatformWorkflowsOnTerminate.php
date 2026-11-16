<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Event;

use App\Module\Workflow\Application\Engine\Message\RunPlatformEvents;
use App\Module\Workflow\Application\PlatformWorkflows;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The platform's events of a request or a command go to the worker once it is done: the system
 * admin's workflows run there.
 */
final class RunPlatformWorkflowsOnTerminate
{
    public function __construct(
        private readonly PlatformWorkflows $platform,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function __invoke(): void
    {
        $events = $this->platform->takePending();
        if ([] !== $events) {
            $this->bus->dispatch(new RunPlatformEvents($events));
        }
    }
}
