<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Dbal;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\Timing;
use App\Module\Workflow\Application\Definition\TriggerEvent;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Shared\Database\WorkspaceScope;
use App\Shared\Workspace\WorkspaceSwitched;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read once per request, object and moment: every record write asks.
 */
#[AsAlias(ActiveWorkflows::class)]
final class DbalActiveWorkflows implements ActiveWorkflows, ResetInterface
{
    /** @var array<string, list<ActiveWorkflow>> */
    private array $read = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function triggeredOn(string $object, Timing $when): array
    {
        $key = $object.'|'.$when->value;
        if (isset($this->read[$key])) {
            return $this->read[$key];
        }

        return $this->read[$key] = $this->select(
            "v.definition -> 'trigger' ->> 'object' = ? AND v.definition -> 'trigger' ->> 'when' = ? AND v.definition -> 'trigger' ->> 'on' <> 'manual'",
            [$object, $when->value],
        );
    }

    public function manualOn(string $object): array
    {
        return $this->read[$object.'|manual'] ??= $this->select("v.definition -> 'trigger' ->> 'object' = ? AND v.definition -> 'trigger' ->> 'on' = 'manual'", [$object]);
    }

    public function onPlatform(TriggerEvent $on): array
    {
        // Not kept: read once per event, after the response, in the system session.
        return $this->select("v.definition -> 'trigger' ->> 'on' = ?", [$on->value]);
    }

    public function byId(string $workflowId): ?ActiveWorkflow
    {
        if (!Uuid::isValid($workflowId)) {
            return null;
        }

        return $this->select('w.id = ?', [$workflowId])[0] ?? null;
    }

    /**
     * What was read was another workspace's, or the system's.
     */
    #[AsEventListener(event: WorkspaceSwitched::class)]
    public function forget(): void
    {
        $this->read = [];
    }

    public function reset(): void
    {
        $this->forget();
    }

    /**
     * @param list<string> $parameters
     *
     * @return list<ActiveWorkflow>
     */
    private function select(string $where, array $parameters): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT w.id, w.name, v.id AS version_id, v.activated_by, v.definition
             FROM core.workflow_version v JOIN core.workflow w ON w.id = v.workflow_id
             WHERE v.status = 'active' AND %s AND %s
             ORDER BY w.position, w.name",
            WorkspaceScope::currentOrSystem('v.workspace_id'),
            $where,
        ), $parameters);

        $parser = new DefinitionParser();

        $workflows = [];
        foreach ($rows as $row) {
            [$id, $name, $versionId, $definition] = [$row['id'], $row['name'], $row['version_id'], $row['definition']];
            if (!\is_string($id) || !\is_string($name) || !\is_string($versionId) || !\is_string($definition)) {
                continue;
            }
            $workflows[] = new ActiveWorkflow(
                $id,
                $name,
                $versionId,
                \is_string($row['activated_by']) ? $row['activated_by'] : null,
                // Checked when it was activated: read back as stored.
                $parser->fromArray((array) json_decode($definition, true, 64, \JSON_THROW_ON_ERROR)),
            );
        }

        return $workflows;
    }
}
