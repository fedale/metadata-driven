<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Dbal;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\Timing;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Shared\Database\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
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
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            "SELECT w.id, w.name, v.id AS version_id, v.activated_by, v.definition
             FROM core.workflow_version v JOIN core.workflow w ON w.id = v.workflow_id
             WHERE v.status = 'active' AND v.workspace_id = %s
               AND v.definition -> 'trigger' ->> 'object' = ? AND v.definition -> 'trigger' ->> 'when' = ?
             ORDER BY w.position, w.name",
            WorkspaceScope::CURRENT,
        ), [$object, $when->value]);

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

        return $this->read[$key] = $workflows;
    }

    public function forget(): void
    {
        $this->read = [];
    }

    public function reset(): void
    {
        $this->forget();
    }
}
