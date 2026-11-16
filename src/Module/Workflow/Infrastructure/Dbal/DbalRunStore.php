<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Dbal;

use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\RunStore;
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
            $this->connection->insert('core.workflow_run', [
                'id' => $run->id,
                'workflow_id' => $run->workflow->workflowId,
                'version_id' => $run->workflow->versionId,
                'status' => $run->status,
                'object' => $run->object,
                'record_id' => $run->recordId,
                'action' => $run->action,
                'timing' => $run->timing,
                'triggered_by' => $run->triggeredBy,
                'depth' => $run->depth,
                'chain' => self::json($run->chain),
                'error' => $run->error,
                'started_at' => $run->startedAt->format(self::TIMESTAMP),
                'finished_at' => ($run->finishedAt ?? new \DateTimeImmutable())->format(self::TIMESTAMP),
            ]);
            foreach ($run->steps as $position => $step) {
                $this->connection->insert('core.workflow_run_step', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'run_id' => $run->id,
                    'position' => $position,
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
            \sprintf('DELETE FROM core.workflow_run WHERE %s AND started_at < ?', WorkspaceScope::currentOrSystem('workspace_id')),
            [$before->format(self::TIMESTAMP)],
        );
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
