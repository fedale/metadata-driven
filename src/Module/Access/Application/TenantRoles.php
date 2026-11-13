<?php

declare(strict_types=1);

namespace App\Module\Access\Application;

use App\Module\Access\Contract\TenantRolesInterface;
use Fedale\RbacBundle\Contract\AssignmentStorageInterface;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Fedale\RbacBundle\Contract\RbacManagerInterface;

/**
 * Through fedale/rbac-bundle, whose tables are the current tenant's (its RBAC schema is in the
 * session's search_path).
 */
final class TenantRoles implements TenantRolesInterface
{
    public function __construct(
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
