<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Repository;

use Symfony\Component\Uid\Uuid;

/**
 * Which tenants a user may sign in to. Users are global (one email, one password); a membership
 * lets them into one tenant, where their roles are that tenant's.
 */
interface MembershipRepository
{
    /**
     * @return bool false when the user was a member already
     */
    public function add(string $tenantId, Uuid $userId): bool;

    /**
     * @return bool false when the user was no member
     */
    public function remove(string $tenantId, Uuid $userId): bool;

    public function isMember(string $tenantId, Uuid $userId): bool;

    /**
     * @return list<string> the ids of the user's tenants
     */
    public function tenantsOf(Uuid $userId): array;

    /**
     * @return list<string> the emails of the tenant's members, sorted
     */
    public function membersOf(string $tenantId): array;

    /**
     * @return list<array{email: string, first_name: string|null, last_name: string|null, last_login_at: string|null}> by name, then email
     */
    public function membersWithProfiles(string $tenantId): array;
}
