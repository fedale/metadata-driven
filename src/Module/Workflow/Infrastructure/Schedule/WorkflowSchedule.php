<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Schedule;

use App\Module\Workflow\Application\Engine\Message\CheckSchedules;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Every minute, the workflows on a schedule whose time is come (ScheduledWorkflows): consumed by
 * the worker as the "scheduler_workflows" transport, by one worker only.
 */
#[AsSchedule('workflows')]
final class WorkflowSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 minute', new CheckSchedules()))
            // A worker restarted goes on from where it was, the minutes it missed but the last skipped.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
