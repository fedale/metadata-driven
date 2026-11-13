<?php

declare(strict_types=1);

namespace App\Module\Access\Application;

use App\Module\Access\Contract\TenantRolesInterface;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\AssignmentStorageInterface;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;

/**
 * Through fedale/rbac-bundle, whose tables are the current tenant's (its RBAC schema is in the
 * session's search_path).
 */
final class TenantRoles implements TenantRolesInterface
{
    /** As the roles rbac:item:add makes by hand: ROLE_ and capitals, as Symfony expects them. */
    private const string ROLE_NAME = '/^ROLE_[A-Z0-9_]{1,59}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly ItemStorageInterface $items,
        private readonly AssignmentStorageInterface $assignments,
        private readonly RbacManagerInterface $rbac,
    ) {
    }

    public function roles(): array
    {
        $roles = [];
        foreach ($this->items->allItems() as $item) {
            if ($item->isRole()) {
                $roles[$item->name] = $item->description;
            }
        }
        ksort($roles);

        return $roles;
    }

    public function rolesOf(string $email): array
    {
        $roles = array_values(array_intersect($this->assignments->getAssignments($email), array_keys($this->roles())));
        sort($roles);

        return $roles;
    }

    public function assignmentsOf(string $email): array
    {
        $items = array_values($this->assignments->getAssignments($email));
        sort($items);

        return $items;
    }

    public function revokeAll(string $email): void
    {
        foreach ($this->assignmentsOf($email) as $item) {
            $this->rbac->revoke($email, $item);
        }
    }

    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->items->allItems() as $item) {
            if ($item->isPermission()) {
                $permissions[$item->name] = $item->description;
            }
        }
        ksort($permissions);

        return $permissions;
    }

    public function permissionsOf(string $role): array
    {
        return $this->childrenOf($role, array_keys($this->permissions()));
    }

    public function childRolesOf(string $role): array
    {
        return $this->childrenOf($role, array_keys($this->roles()));
    }

    public function assigneesOf(string $role): array
    {
        // The bundle reads assignments per user only: its table, in the tenant's RBAC schema
        // (first after core in the session's search_path).
        /** @var list<string> $emails */
        $emails = $this->connection->fetchFirstColumn('SELECT user_id FROM auth_assignment WHERE item_name = ? ORDER BY user_id', [$role]);

        return $emails;
    }

    public function rolesContaining(string $role): array
    {
        $roles = array_keys($this->roles());
        $found = [];
        $queue = [$role];
        while ([] !== $queue) {
            foreach ($this->items->getParents(array_shift($queue)) as $parent) {
                if (\in_array($parent, $roles, true) && !\in_array($parent, $found, true) && $parent !== $role) {
                    $found[] = $parent;
                    $queue[] = $parent;
                }
            }
        }
        sort($found);

        return $found;
    }

    public function createRole(string $name, ?string $description, array $permissions): void
    {
        if (1 !== preg_match(self::ROLE_NAME, $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no role name: ROLE_ and capital letters, digits or underscores, 64 characters at most.', $name));
        }
        if (null !== $this->items->getItem($name)) {
            throw new \InvalidArgumentException(\sprintf('There is a "%s" already.', $name));
        }
        $this->assertPermissions($permissions);
        $this->rbac->addItem($name, AuthItemType::ROLE, $description);
        foreach ($permissions as $permission) {
            $this->rbac->addChild($name, $permission);
        }
    }

    public function setPermissions(string $role, array $permissions): void
    {
        $this->assertRole($role);
        $this->assertPermissions($permissions);
        $held = $this->permissionsOf($role);
        foreach (array_diff($permissions, $held) as $permission) {
            $this->rbac->addChild($role, $permission);
        }
        foreach (array_diff($held, $permissions) as $permission) {
            $this->rbac->removeChild($role, $permission);
        }
    }

    public function deleteRole(string $role): void
    {
        $this->assertRole($role);
        $this->rbac->removeItem($role);
    }

    /**
     * @param list<string> $among
     *
     * @return list<string>
     */
    private function childrenOf(string $role, array $among): array
    {
        $children = array_values(array_intersect($this->items->getChildren($role), $among));
        sort($children);

        return $children;
    }

    private function assertRole(string $role): void
    {
        if (!\array_key_exists($role, $this->roles())) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no role of this tenant.', $role));
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function assertPermissions(array $permissions): void
    {
        $unknown = array_diff($permissions, array_keys($this->permissions()));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no permission of this tenant.', implode('", "', $unknown)));
        }
    }

    public function setRoles(string $email, array $roles): void
    {
        $unknown = array_diff($roles, array_keys($this->roles()));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no role of this tenant.', implode('", "', $unknown)));
        }
        $held = $this->rolesOf($email);
        foreach (array_diff($roles, $held) as $role) {
            $this->rbac->assign($email, $role);
        }
        foreach (array_diff($held, $roles) as $role) {
            $this->rbac->revoke($email, $role);
        }
    }
}
