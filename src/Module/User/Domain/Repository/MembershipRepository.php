<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Repository;

use Symfony\Component\Uid\Uuid;

/**
 * Which workspaces a user may sign in to. Users are global (one email, one password); a membership
 * lets them into one workspace, where their roles are that workspace's.
 */
interface MembershipRepository
{
    /**
     * @return bool false when the user was a member already
     */
    public function add(string $workspaceId, Uuid $userId): bool;

    /**
     * @return bool false when the user was no member
     */
    public function remove(string $workspaceId, Uuid $userId): bool;

    public function isMember(string $workspaceId, Uuid $userId): bool;

    /**
     * @return list<string> the ids of the user's workspaces
     */
    public function workspacesOf(Uuid $userId): array;

    /**
     * @return list<string> the emails of the workspace's members, sorted
     */
    public function membersOf(string $workspaceId): array;

    /**
     * @return list<array{email: string, first_name: string|null, last_name: string|null, last_login_at: string|null, locale: string|null, notify_by_email: bool|null, is_active: bool}> by name, then email
     */
    public function membersWithProfiles(string $workspaceId): array;
}
