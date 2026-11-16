<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

use App\Module\Workflow\Application\PlatformWorkflows;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunPlatformEventsHandler
{
    public function __construct(
        private readonly PlatformWorkflows $platform,
    ) {
    }

    public function __invoke(RunPlatformEvents $message): void
    {
        $this->platform->run($message->events);
    }
}
