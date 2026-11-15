<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Dbal;

use App\Module\Workflow\Application\RunQueries;
use App\Shared\Database\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RunQueries::class)]
final class DbalRunQueries implements RunQueries
{
    private const string COLUMNS = 'id, status, object, record_id, action, timing, triggered_by, depth, error, started_at';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function summary(): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT workflow_id, MAX(started_at) AS last, COUNT(*) FILTER (WHERE status <> 'completed' AND started_at > NOW() - INTERVAL '1 day') AS failed
             FROM core.workflow_run WHERE workspace_id = %s GROUP BY workflow_id",
            WorkspaceScope::CURRENT,
        ));
        $summary = [];
        foreach ($rows as $row) {
            $summary[self::string($row['workflow_id'])] = ['last' => self::nullableString($row['last']), 'failed' => self::int($row['failed'])];
        }

        return $summary;
    }

    public function runsOf(string $workflowId, ?string $status, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM core.workflow_run WHERE workspace_id = %s AND workflow_id = ? AND (?::text IS NULL OR status = ?) ORDER BY started_at DESC LIMIT %d', self::COLUMNS, WorkspaceScope::CURRENT, $limit),
            [$workflowId, $status, $status],
        );

        return array_map(self::row(...), $rows);
    }

    public function run(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT %s, workflow_id, chain, finished_at FROM core.workflow_run WHERE workspace_id = %s AND id = ?', self::COLUMNS, WorkspaceScope::CURRENT),
            [$id],
        );
        if (false === $row) {
            return null;
        }
        $steps = array_map(static fn (array $step): array => [
            'key' => self::string($step['key']),
            'type' => self::string($step['type']),
            'status' => self::string($step['status']),
            'input' => self::json($step['input']),
            'output' => self::json($step['output']),
            'error' => self::nullableString($step['error']),
            'duration_ms' => self::int($step['duration_ms']),
        ], $this->connection->fetchAllAssociative('SELECT key, type, status, input, output, error, duration_ms FROM core.workflow_run_step WHERE run_id = ? ORDER BY position', [$id]));

        return [
            ...self::row($row),
            'workflow_id' => self::string($row['workflow_id']),
            'chain' => self::json($row['chain']),
            'finished_at' => self::string($row['finished_at']),
            'steps' => $steps,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, status: string, object: string, record_id: ?string, action: string, timing: string, triggered_by: ?string, depth: int, error: ?string, started_at: string}
     */
    private static function row(array $row): array
    {
        return [
            'id' => self::string($row['id']),
            'status' => self::string($row['status']),
            'object' => self::string($row['object']),
            'record_id' => self::nullableString($row['record_id']),
            'action' => self::string($row['action']),
            'timing' => self::string($row['timing']),
            'triggered_by' => self::nullableString($row['triggered_by']),
            'depth' => self::int($row['depth']),
            'error' => self::nullableString($row['error']),
            'started_at' => self::string($row['started_at']),
        ];
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function json(mixed $value): mixed
    {
        return \is_string($value) ? json_decode($value, true, 512, \JSON_THROW_ON_ERROR) : null;
    }
}
