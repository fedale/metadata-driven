<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * What the signed-in user may do. Anonymous users may do nothing.
 */
interface AccessCheckerInterface
{
    public function canRecords(string $objectName, RecordAction $action): bool;

    public function canAdminister(): bool;

    /**
     * The members page: who may sign in to the tenant, with which roles.
     */
    public function canManageMembers(): bool;

    /**
     * The roles page: which permissions each role of the tenant gives.
     */
    public function canManageRoles(): bool;

    /**
     * Whether the current user holds an RBAC item, a role or a permission, directly or through
     * the role hierarchy (the super admin holds them all). What one may give or take on the
     * members page: no more than one has.
     */
    public function holds(string $item): bool;

    /**
     * Whether the current user may give an RBAC item to someone, or take it: a permission they
     * hold; a role they hold, or every permission of which, through the roles it contains, they
     * hold. Giving it then gives nothing they don't have. The super admin role, which lists no
     * permission and passes every check, only a super admin gives.
     */
    public function canGrant(string $item): bool;
}
