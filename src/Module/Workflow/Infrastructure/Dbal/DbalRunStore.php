<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Dbal;

use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\RunStore;
use App\Module\Workflow\Application\Engine\WaitingRun;
use App\Shared\Database\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * The runs, written with DBAL once the transaction they ran in ended: never with the ORM, whose
 * unit of work may hold what that transaction rolled back. workspace_id comes from the session
 * (the insert trigger), as for every workspace table.
 */
#[AsAlias(RunStore::class)]
final class DbalRunStore implements RunStore
{
    private const string TIMESTAMP = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function save(RunLog $run): void
    {
        $this->connection->transactional(function () use ($run): void {
            $state = [
                'status' => $run->status,
                'chain' => self::json($run->chain),
                'error' => $run->error,
                'finished_at' => ($run->finishedAt ?? new \DateTimeImmutable())->format(self::TIMESTAMP),
                'resume_at' => $run->resumeAt?->format(self::TIMESTAMP),
                'resume_step' => $run->resumeStep,
            ];
            if ($run->stepOffset > 0) {
                // A resumed run: its row again, its new steps after the others.
                $this->connection->update('core.workflow_run', $state, ['id' => $run->id]);
            } else {
                $this->connection->insert('core.workflow_run', [
                    'id' => $run->id,
                    'workflow_id' => $run->workflow->workflowId,
                    'version_id' => $run->workflow->versionId,
                    'object' => $run->object,
                    'record_id' => $run->recordId,
                    'action' => $run->action,
                    'timing' => $run->timing,
                    'triggered_by' => $run->triggeredBy,
                    'depth' => $run->depth,
                    'started_at' => $run->startedAt->format(self::TIMESTAMP),
                    ...$state,
                ]);
            }
            foreach ($run->steps as $position => $step) {
                $this->connection->insert('core.workflow_run_step', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'run_id' => $run->id,
                    'position' => $run->stepOffset + $position,
                    'key' => $step['key'],
                    'type' => $step['type'],
                    'status' => $step['status'],
                    'input' => self::json($step['input']),
                    'output' => null === $step['output'] ? null : self::json($step['output']),
                    'error' => $step['error'],
                    'duration_ms' => $step['duration_ms'],
                ]);
            }
            if ([] !== $run->steps) {
                // The workspace_id of the conflict comes from the insert trigger, which runs first.
                // Counted per workspace: the system's runs (no workspace) count for none.
                $this->connection->executeStatement(
                    \sprintf('INSERT INTO core.workflow_usage (day, steps) SELECT CURRENT_DATE, ? WHERE %s IS NOT NULL
                     ON CONFLICT (workspace_id, day) DO UPDATE SET steps = core.workflow_usage.steps + EXCLUDED.steps', WorkspaceScope::CURRENT),
                    [\count($run->steps)],
                );
            }
        });
    }

    public function waiting(string $id): ?WaitingRun
    {
        $row = $this->connection->fetchAssociative(
            \sprintf("SELECT r.*, (SELECT COUNT(*) FROM core.workflow_run_step s WHERE s.run_id = r.id) AS step_count FROM core.workflow_run r WHERE %s AND r.id = ? AND r.status = 'waiting'", WorkspaceScope::currentOrSystem('r.workspace_id')),
            [$id],
        );
        if (false === $row) {
            return null;
        }
        $chain = json_decode(\is_string($row['chain']) ? $row['chain'] : '{}', true, 512, \JSON_THROW_ON_ERROR);

        return new WaitingRun(
            self::text($row['id']),
            self::text($row['workflow_id']),
            self::text($row['version_id']),
            self::text($row['object']),
            \is_string($row['record_id']) ? $row['record_id'] : null,
            self::text($row['action']),
            self::text($row['timing']),
            \is_string($row['triggered_by']) ? $row['triggered_by'] : null,
            is_numeric($row['depth']) ? (int) $row['depth'] : 1,
            \is_array($chain) ? array_combine(array_map(strval(...), array_keys($chain)), array_values($chain)) : [],
            is_numeric($row['resume_step']) ? (int) $row['resume_step'] : 0,
            is_numeric($row['step_count']) ? (int) $row['step_count'] : 0,
            new \DateTimeImmutable(self::text($row['started_at'])),
        );
    }

    public function stepsThisMonth(): int
    {
        $steps = $this->connection->fetchOne(
            \sprintf("SELECT COALESCE(SUM(steps), 0) FROM core.workflow_usage WHERE workspace_id = %s AND day >= date_trunc('month', CURRENT_DATE)", WorkspaceScope::CURRENT),
        );

        return is_numeric($steps) ? (int) $steps : 0;
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        $count = $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM core.workflow_run WHERE %s AND started_at >= ?', WorkspaceScope::currentOrSystem('workspace_id')),
            [$since->format(self::TIMESTAMP)],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function deleteBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            // A run that waits is kept until it resumed.
            \sprintf("DELETE FROM core.workflow_run WHERE %s AND started_at < ? AND status <> 'waiting'", WorkspaceScope::currentOrSystem('workspace_id')),
            [$before->format(self::TIMESTAMP)],
        );
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function json(mixed $value): string
    {
        return json_encode(self::storable($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * A run's data as jsonb takes it. A run holds what came from outside (an http_request's
     * answer, a webhook's body), and it is stored after its writes committed: what JSON or
     * PostgreSQL refuses must not fail the run then. A text that is not UTF-8 has its invalid
     * bytes replaced, a NUL character is dropped (jsonb has none), a number that is no number
     * (INF, NAN) is null.
     */
    private static function storable(mixed $value): mixed
    {
        if (\is_string($value)) {
            return self::storableText($value);
        }
        if (\is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (!\is_array($value)) {
            return $value;
        }
        $storable = [];
        foreach ($value as $key => $item) {
            $storable[\is_string($key) ? self::storableText($key) : $key] = self::storable($item);
        }

        return $storable;
    }

    private static function storableText(string $text): string
    {
        return str_replace("\0", '', mb_scrub($text, 'UTF-8'));
    }
}
