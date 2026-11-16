<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Event;

use App\Module\Workflow\Application\PlatformWorkflows;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The system admin's workflows run once the response is sent, or the command done.
 */
final class RunPlatformWorkflowsOnTerminate
{
    public function __construct(
        private readonly PlatformWorkflows $platform,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function __invoke(): void
    {
        $this->platform->runPending();
    }
}
