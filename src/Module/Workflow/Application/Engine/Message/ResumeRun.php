<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

/**
 * A run that waits, sent delayed until it resumes (ResumeRunHandler).
 */
final readonly class ResumeRun
{
    /**
     * @param int $delays how many times it went back to the queue: too many runs
     */
    public function __construct(
        public string $runId,
        public string $workspaceId,
        public int $delays = 0,
    ) {
    }

    public function delayed(): self
    {
        return new self($this->runId, $this->workspaceId, $this->delays + 1);
    }
}
