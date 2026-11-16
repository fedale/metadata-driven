<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

/**
 * A run that waits, sent delayed until it resumes (ResumeRunHandler).
 */
final readonly class ResumeRun
{
    public function __construct(
        public string $runId,
        public string $workspaceId,
    ) {
    }
}
