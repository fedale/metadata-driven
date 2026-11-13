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
     * Whether the current user holds an RBAC item, a role or a permission, directly or through
     * the role hierarchy (the super admin holds them all). What one may give or take on the
     * members page: no more than one has.
     */
    public function holds(string $item): bool;
}
