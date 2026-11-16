<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

use App\Module\Workflow\Application\ScheduledWorkflows;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckSchedulesHandler
{
    public function __construct(
        private readonly ScheduledWorkflows $scheduled,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckSchedules $message): void
    {
        $this->scheduled->queueDue($this->clock->now());
    }
}
