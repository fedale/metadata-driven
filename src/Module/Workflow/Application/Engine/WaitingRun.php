<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

/**
 * A run that waits, as stored: what it needs to resume.
 */
final readonly class WaitingRun
{
    /**
     * @param array<string, mixed> $chain
     */
    public function __construct(
        public string $id,
        public string $workflowId,
        public string $versionId,
        public string $object,
        public ?string $recordId,
        public string $action,
        public string $timing,
        public ?string $triggeredBy,
        public int $depth,
        public array $chain,
        public int $resumeStep,
        public int $stepCount,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
