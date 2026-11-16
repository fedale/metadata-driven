<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

/**
 * Sent every minute by the workflows' schedule (WorkflowSchedule): the scheduled workflows whose
 * time is come go to the queue.
 */
final readonly class CheckSchedules
{
}
