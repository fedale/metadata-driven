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

    public readonly string $id;

    public string $status = self::COMPLETED;

    public ?string $error = null;

    /** @var array<string, mixed> */
    public array $chain = [];

    public ?\DateTimeImmutable $finishedAt = null;

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
    ) {
        $this->id = Uuid::v7()->toRfc4122();
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
