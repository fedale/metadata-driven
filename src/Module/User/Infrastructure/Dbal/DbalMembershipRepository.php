<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Dbal;

use App\Module\User\Domain\Repository\MembershipRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * core.workspace_membership, the system's table: every query names its workspace, never the session's,
 * since a user's workspaces are listed from any of them.
 */
final class DbalMembershipRepository implements MembershipRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(string $workspaceId, Uuid $userId): bool
    {
        return 1 === $this->connection->executeStatement(
            'INSERT INTO core.workspace_membership (workspace_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
            [$workspaceId, $userId->toRfc4122()],
        );
    }

    public function remove(string $workspaceId, Uuid $userId): bool
    {
        return 1 === $this->connection->executeStatement(
            'DELETE FROM core.workspace_membership WHERE workspace_id = ? AND user_id = ?',
            [$workspaceId, $userId->toRfc4122()],
        );
    }

    public function isMember(string $workspaceId, Uuid $userId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM core.workspace_membership WHERE workspace_id = ? AND user_id = ?',
            [$workspaceId, $userId->toRfc4122()],
        );
    }

    public function workspacesOf(Uuid $userId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT workspace_id FROM core.workspace_membership WHERE user_id = ? ORDER BY created_at, workspace_id',
            [$userId->toRfc4122()],
        );

        return $ids;
    }

    public function membersWithProfiles(string $workspaceId): array
    {
        /** @var list<array{email: string, first_name: string|null, last_name: string|null, last_login_at: string|null, locale: string|null, notify_by_email: bool|null, is_active: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.email, p.first_name, p.last_name, u.last_login_at, p.locale, p.notify_by_email, u.is_active
               FROM core.workspace_membership m
               JOIN core.app_user u ON u.id = m.user_id
               LEFT JOIN core.app_user_profile p ON p.user_id = u.id
              WHERE m.workspace_id = ?
              ORDER BY lower(coalesce(nullif(trim(concat(p.first_name, \' \', p.last_name)), \'\'), u.email)), u.email',
            [$workspaceId],
        );

        return $rows;
    }

    public function membersOf(string $workspaceId): array
    {
        /** @var list<string> $emails */
        $emails = $this->connection->fetchFirstColumn(
            'SELECT u.email FROM core.workspace_membership m JOIN core.app_user u ON u.id = m.user_id WHERE m.workspace_id = ? ORDER BY u.email',
            [$workspaceId],
        );

        return $emails;
    }
}
