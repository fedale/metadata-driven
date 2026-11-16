<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Dbal;

use App\Module\Workflow\Application\Webhook\WebhookTokens;
use App\Shared\Database\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WebhookTokens::class)]
final class DbalWebhookTokens implements WebhookTokens
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function issue(string $workflowId, ?string $by): string
    {
        // 32 random bytes: a token no one guesses, hashed alone (no salt needed).
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->executeStatement(
            \sprintf('INSERT INTO core.workflow_webhook (workflow_id, token_hash, made_by, made_at) VALUES (?, ?, ?, NOW())
             ON CONFLICT (workflow_id) DO UPDATE SET token_hash = EXCLUDED.token_hash, made_by = EXCLUDED.made_by, made_at = EXCLUDED.made_at
             WHERE core.workflow_webhook.workspace_id = %s', WorkspaceScope::CURRENT),
            [$workflowId, hash('sha256', $token), $by],
        );

        return $token;
    }

    public function has(string $workflowId): bool
    {
        return null !== $this->describe($workflowId);
    }

    public function describe(string $workflowId): ?array
    {
        if (!Uuid::isValid($workflowId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(\sprintf('SELECT made_by, made_at FROM core.workflow_webhook WHERE workspace_id = %s AND workflow_id = ?', WorkspaceScope::CURRENT), [$workflowId]);

        return false === $row ? null : ['made_by' => \is_string($row['made_by']) ? $row['made_by'] : null, 'made_at' => \is_string($row['made_at']) ? $row['made_at'] : ''];
    }

    public function matches(string $workflowId, string $token): bool
    {
        if (!Uuid::isValid($workflowId)) {
            return false;
        }
        $hash = $this->connection->fetchOne(\sprintf('SELECT token_hash FROM core.workflow_webhook WHERE workspace_id = %s AND workflow_id = ?', WorkspaceScope::CURRENT), [$workflowId]);

        return \is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }
}
