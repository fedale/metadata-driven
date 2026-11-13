<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Dbal;

use App\Module\User\Domain\Repository\MembershipRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * core.tenant_membership, the landlord's table: every query names its tenant, never the session's,
 * since a user's tenants are listed from any of them.
 */
final class DbalMembershipRepository implements MembershipRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(string $tenantId, Uuid $userId): bool
    {
        return 1 === $this->connection->executeStatement(
            'INSERT INTO core.tenant_membership (tenant_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
            [$tenantId, $userId->toRfc4122()],
        );
    }

    public function remove(string $tenantId, Uuid $userId): bool
    {
        return 1 === $this->connection->executeStatement(
            'DELETE FROM core.tenant_membership WHERE tenant_id = ? AND user_id = ?',
            [$tenantId, $userId->toRfc4122()],
        );
    }

    public function isMember(string $tenantId, Uuid $userId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM core.tenant_membership WHERE tenant_id = ? AND user_id = ?',
            [$tenantId, $userId->toRfc4122()],
        );
    }

    public function tenantsOf(Uuid $userId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT tenant_id FROM core.tenant_membership WHERE user_id = ? ORDER BY created_at, tenant_id',
            [$userId->toRfc4122()],
        );

        return $ids;
    }

    public function membersWithProfiles(string $tenantId): array
    {
        /** @var list<array{email: string, first_name: string|null, last_name: string|null, last_login_at: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.email, p.first_name, p.last_name, u.last_login_at
               FROM core.tenant_membership m
               JOIN core.app_user u ON u.id = m.user_id
               LEFT JOIN core.app_user_profile p ON p.user_id = u.id
              WHERE m.tenant_id = ?
              ORDER BY lower(coalesce(nullif(trim(concat(p.first_name, \' \', p.last_name)), \'\'), u.email)), u.email',
            [$tenantId],
        );

        return $rows;
    }

    public function membersOf(string $tenantId): array
    {
        /** @var list<string> $emails */
        $emails = $this->connection->fetchFirstColumn(
            'SELECT u.email FROM core.tenant_membership m JOIN core.app_user u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY u.email',
            [$tenantId],
        );

        return $emails;
    }
}
