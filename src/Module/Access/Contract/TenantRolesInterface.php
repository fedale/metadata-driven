<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * The roles of the current tenant (its RBAC tables), and who holds them: for the members page.
 * Users are named by their email, as the RBAC assignments are.
 */
interface TenantRolesInterface
{
    /**
     * @return array<string, string|null> role name => description, by name
     */
    public function roles(): array;

    /**
     * @return list<string> the roles assigned to the user directly, by name
     */
    public function rolesOf(string $email): array;

    /**
     * @return list<string> every item assigned to the user directly in the tenant, roles and
     *                      permissions (rbac:assign), by name
     */
    public function assignmentsOf(string $email): array;

    /**
     * Gives the user exactly these roles of the tenant, assigning and revoking what differs.
     * Permissions assigned directly (rbac:assign) are left alone.
     *
     * @param list<string> $roles
     *
     * @throws \InvalidArgumentException a name that is no role of the tenant
     */
    public function setRoles(string $email, array $roles): void;
}
