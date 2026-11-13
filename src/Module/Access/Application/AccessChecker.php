<?php

declare(strict_types=1);

namespace App\Module\Access\Application;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use Fedale\RbacBundle\Contract\AccessManagerInterface;
use Fedale\RbacBundle\Contract\ItemStorageInterface;

/**
 * Asks the RBAC graph: assignments, role hierarchy and rules, the super admin short-circuit.
 */
final class AccessChecker implements AccessCheckerInterface
{
    public function __construct(
        private readonly AccessManagerInterface $access,
        private readonly ItemStorageInterface $items,
    ) {
    }

    public function canRecords(string $objectName, RecordAction $action): bool
    {
        return $this->access->can(Permissions::record($objectName, $action));
    }

    public function canAdminister(): bool
    {
        return $this->access->can(Permissions::ADMINISTER);
    }

    public function canManageMembers(): bool
    {
        return $this->access->can(Permissions::MEMBERS);
    }

    public function canManageRoles(): bool
    {
        return $this->access->can(Permissions::ROLES);
    }

    public function holds(string $item): bool
    {
        return $this->access->can($item);
    }

    public function canGrant(string $item): bool
    {
        if ($this->holds($item)) {
            return true;
        }
        $found = $this->items->getItem($item);
        if (null === $found || !$found->isRole() || Permissions::SUPER_ADMIN_ROLE === $item) {
            return false;
        }

        return $this->holdsAllOf($item, []);
    }

    /**
     * Whether the user holds every permission of the role, through the roles it contains.
     *
     * @param list<string> $seen the roles walked already: a cycle in the hierarchy ends here
     */
    private function holdsAllOf(string $role, array $seen): bool
    {
        foreach ($this->items->getChildren($role) as $child) {
            $item = $this->items->getItem($child);
            if (null === $item || \in_array($child, $seen, true)) {
                continue;
            }
            $holds = $item->isRole()
                ? Permissions::SUPER_ADMIN_ROLE !== $child && ($this->holds($child) || $this->holdsAllOf($child, [...$seen, $role]))
                : $this->holds($child);
            if (!$holds) {
                return false;
            }
        }

        return true;
    }
}
