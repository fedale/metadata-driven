<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

/**
 * An after-run, queued once its write committed: run by the worker (RunWorkflowHandler), in its
 * workspace, as the user who triggered it.
 */
final readonly class RunWorkflow
{
    /**
     * @param array<string, mixed> $trigger what $trigger holds, as it was when written
     * @param int                  $delays  how many times it went back to the queue: too many runs
     */
    public function __construct(
        public string $workspaceId,
        public string $workflowId,
        public string $versionId,
        public string $object,
        public ?string $recordId,
        public string $action,
        public array $trigger,
        public ?string $triggeredBy,
        public int $depth,
        public int $delays = 0,
    ) {
    }

    public function delayed(): self
    {
        return new self($this->workspaceId, $this->workflowId, $this->versionId, $this->object, $this->recordId, $this->action, $this->trigger, $this->triggeredBy, $this->depth, $this->delays + 1);
    }
}
