<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

/**
 * A scheduled workflow whose time came, run by the worker (RunScheduledHandler).
 */
final readonly class RunScheduled
{
    public function __construct(
        public string $workspaceId,
        public string $workflowId,
        public string $versionId,
        /** The minute it was due, ISO 8601, UTC. */
        public string $scheduledAt,
    ) {
    }
}
