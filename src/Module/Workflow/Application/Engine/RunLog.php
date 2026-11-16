<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use Symfony\Component\Uid\Uuid;

/**
 * One run, as it goes: stored once the transaction it ran in ended (RunStore).
 */
final class RunLog
{
    public const string COMPLETED = 'completed';
    public const string FAILED = 'failed';
    public const string REFUSED = 'refused';
    /** Stopped at a wait step: it resumes at resumeAt, from resumeStep. */
    public const string WAITING = 'waiting';
    /** Waited, and the version it ran was no longer active: it ends there. */
    public const string CANCELLED = 'cancelled';

    public readonly string $id;

    public string $status = self::COMPLETED;

    public ?string $error = null;

    /** @var array<string, mixed> */
    public array $chain = [];

    public ?\DateTimeImmutable $finishedAt = null;

    /** A waiting run's: when it resumes, and the index of the definition's step it resumes from. */
    public ?\DateTimeImmutable $resumeAt = null;

    public ?int $resumeStep = null;

    /** @var array<string, mixed>|null a called workflow's: the values its return step gave, which end the run */
    public ?array $returned = null;

    /** The steps logged before this part of the run (a resumed run's), already stored. */
    public int $stepOffset = 0;

    /** @var list<array{key: string, type: string, status: string, input: mixed, output: mixed, error: ?string, duration_ms: int}> */
    public array $steps = [];

    public function __construct(
        public readonly ActiveWorkflow $workflow,
        public readonly string $object,
        public readonly ?string $recordId,
        public readonly string $action,
        public readonly string $timing,
        public readonly ?string $triggeredBy,
        public readonly int $depth,
        public readonly \DateTimeImmutable $startedAt,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
    }

    /**
     * Stops at a wait: what ran so far is kept, the rest resumes then.
     */
    public function wait(\DateTimeImmutable $until, int $resumeStep): void
    {
        $this->status = self::WAITING;
        $this->resumeAt = $until;
        $this->resumeStep = $resumeStep;
    }

    /**
     * How many steps this run executed, those of its parts before included.
     */
    public function executed(): int
    {
        return $this->stepOffset + \count($this->steps);
    }

    public function step(string $key, string $type, string $status, mixed $input, mixed $output, ?string $error, int $durationMs): void
    {
        $this->steps[] = ['key' => $key, 'type' => $type, 'status' => $status, 'input' => $input, 'output' => $output, 'error' => $error, 'duration_ms' => $durationMs];
    }

    public function end(string $status, ?string $error = null): void
    {
        $this->status = $status;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }
}
