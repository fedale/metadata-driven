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

    /**
     * Takes away everything assigned to the user directly in the tenant, roles and permissions.
     */
    public function revokeAll(string $email): void;

    /**
     * @return array<string, string|null> permission name => description, by name
     */
    public function permissions(): array;

    /**
     * @return list<string> the permissions the role gives directly, by name
     */
    public function permissionsOf(string $role): array;

    /**
     * @return list<string> the roles the role contains (the hierarchy, rbac:child:add), by name
     */
    public function childRolesOf(string $role): array;

    /**
     * @return list<string> the roles containing the role, directly or through others, by name
     */
    public function rolesContaining(string $role): array;

    /**
     * @param list<string> $permissions
     *
     * @throws \InvalidArgumentException a malformed or taken name, an unknown permission
     */
    public function createRole(string $name, ?string $description, array $permissions): void;

    /**
     * Gives the role exactly these permissions; the roles it contains are left alone.
     *
     * @param list<string> $permissions
     *
     * @throws \InvalidArgumentException no such role, an unknown permission
     */
    public function setPermissions(string $role, array $permissions): void;

    /**
     * @throws \InvalidArgumentException no such role
     */
    public function deleteRole(string $role): void;
}
