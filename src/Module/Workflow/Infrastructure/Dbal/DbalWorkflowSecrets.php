<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Dbal;

use App\Module\Workflow\Application\Secrets\SecretBox;
use App\Module\Workflow\Application\Secrets\WorkflowSecrets;
use App\Shared\Database\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WorkflowSecrets::class)]
final class DbalWorkflowSecrets implements WorkflowSecrets
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SecretBox $box,
    ) {
    }

    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT name, updated_by, updated_at FROM core.workflow_secret WHERE workspace_id = %s ORDER BY name', WorkspaceScope::CURRENT));

        return array_map(static fn (array $row): array => [
            'name' => \is_string($row['name']) ? $row['name'] : '',
            'updated_by' => \is_string($row['updated_by']) ? $row['updated_by'] : null,
            'updated_at' => \is_string($row['updated_at']) ? $row['updated_at'] : '',
        ], $rows);
    }

    public function names(): array
    {
        return array_column($this->all(), 'name');
    }

    public function set(string $name, string $value, ?string $by): void
    {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new \InvalidArgumentException('A name of lowercase letters, digits and underscores, starting with a letter.');
        }
        if ('' === $value) {
            throw new \InvalidArgumentException('A secret holds a value.');
        }
        $this->connection->executeStatement(
            \sprintf('INSERT INTO core.workflow_secret (id, name, value, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
             ON CONFLICT (workspace_id, name) DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = EXCLUDED.updated_at
             WHERE core.workflow_secret.workspace_id = %s', WorkspaceScope::CURRENT),
            [Uuid::v7()->toRfc4122(), $name, $this->box->seal($value), $by],
        );
    }

    public function remove(string $name): bool
    {
        return 1 === $this->connection->executeStatement(\sprintf('DELETE FROM core.workflow_secret WHERE workspace_id = %s AND name = ?', WorkspaceScope::CURRENT), [$name]);
    }

    public function values(): array
    {
        $values = [];
        foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT name, value FROM core.workflow_secret WHERE workspace_id = %s', WorkspaceScope::CURRENT)) as $row) {
            if (\is_string($row['name']) && \is_string($row['value'])) {
                $values[$row['name']] = $this->box->open($row['value']);
            }
        }

        return $values;
    }
}
