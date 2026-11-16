<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

use App\Module\Workflow\Application\Definition\TriggerEvent;

/**
 * The platform's events of a request or a command, sent once it is done: the system admin's
 * workflows run on them in the worker (RunPlatformEventsHandler).
 */
final readonly class RunPlatformEvents
{
    /**
     * @param list<array{TriggerEvent, string, array<string, mixed>}> $events event, workspace id, what $trigger holds
     */
    public function __construct(
        public array $events,
    ) {
    }
}
