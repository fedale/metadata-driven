<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * The runs of the current workspace's workflows, as the admin shows them.
 */
interface RunQueries
{
    /**
     * Per workflow: when it last ran, and its runs that failed in the last day.
     *
     * @return array<string, array{last: ?string, failed: int}> by workflow id
     */
    public function summary(): array;

    /**
     * @return list<array{id: string, status: string, object: string, record_id: ?string, action: string, timing: string, triggered_by: ?string, depth: int, error: string|Message|MessageList|null, started_at: string}> newest first
     */
    public function runsOf(string $workflowId, ?string $status, int $limit = 100): array;

    /**
     * @return array{id: string, workflow_id: string, status: string, object: string, record_id: ?string, action: string, timing: string, triggered_by: ?string, depth: int, error: string|Message|MessageList|null, chain: mixed, started_at: string, finished_at: string, steps: list<array{key: string, type: string, status: string, input: mixed, output: mixed, error: string|Message|MessageList|null, duration_ms: int}>}|null
     */
    public function run(string $id): ?array;
}
