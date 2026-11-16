<?php

declare(strict_types=1);

namespace App\Module\Access\Application;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use Fedale\RbacBundle\Contract\AccessManagerInterface;
use Fedale\RbacBundle\Contract\AssignmentStorageInterface;
use Fedale\RbacBundle\Contract\ItemStorageInterface;

/**
 * Asks the RBAC graph: assignments, role hierarchy and rules, the super admin short-circuit.
 */
final class AccessChecker implements AccessCheckerInterface
{
    public function __construct(
        private readonly AccessManagerInterface $access,
        private readonly ItemStorageInterface $items,
        private readonly AssignmentStorageInterface $assignments,
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

    public function canManageWorkflows(): bool
    {
        return $this->access->can(Permissions::WORKFLOWS);
    }

    public function holds(string $item): bool
    {
        return $this->access->can($item);
    }

    public function canGrant(string $item): bool
    {
        return $this->grantable($item, $this->holds(...));
    }

    public function roleGives(string $role, string $item): bool
    {
        if (null === $this->items->getItem($role)) {
            return false;
        }

        return Permissions::SUPER_ADMIN_ROLE === $role || $role === $item || $this->contains($role, $item, []);
    }

    public function userHolds(string $email, string $item): bool
    {
        return [] !== array_filter($this->assignments->getAssignments($email), fn (string $own): bool => $this->roleGives($own, $item));
    }

    public function userCanGrant(string $email, string $item): bool
    {
        $assigned = $this->assignments->getAssignments($email);

        return $this->grantable($item, fn (string $wanted): bool => [] !== array_filter($assigned, fn (string $own): bool => $this->roleGives($own, $wanted)));
    }

    /**
     * The rule of canGrant(), for whoever $holds tells about: an item they hold; a role, every
     * permission of which, through the roles it contains, they hold. The super admin role, which
     * lists no permission, only to whoever holds it.
     *
     * @param callable(string): bool $holds
     */
    private function grantable(string $item, callable $holds): bool
    {
        if ($holds($item)) {
            return true;
        }
        $found = $this->items->getItem($item);
        if (null === $found || !$found->isRole() || Permissions::SUPER_ADMIN_ROLE === $item) {
            return false;
        }

        return $this->holdsAllOf($item, [], $holds);
    }

    /**
     * Whether every permission of the role, through the roles it contains, is held.
     *
     * @param list<string>           $seen  the roles walked already: a cycle in the hierarchy ends here
     * @param callable(string): bool $holds
     */
    private function holdsAllOf(string $role, array $seen, callable $holds): bool
    {
        foreach ($this->items->getChildren($role) as $child) {
            $item = $this->items->getItem($child);
            if (null === $item || \in_array($child, $seen, true)) {
                continue;
            }
            $held = $item->isRole()
                ? Permissions::SUPER_ADMIN_ROLE !== $child && ($holds($child) || $this->holdsAllOf($child, [...$seen, $role], $holds))
                : $holds($child);
            if (!$held) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the role contains the item, through the roles it contains; containing the super
     * admin role contains everything.
     *
     * @param list<string> $seen the roles walked already
     */
    private function contains(string $role, string $item, array $seen): bool
    {
        foreach ($this->items->getChildren($role) as $child) {
            if ($child === $item || Permissions::SUPER_ADMIN_ROLE === $child) {
                return true;
            }
            if (!\in_array($child, $seen, true) && true === $this->items->getItem($child)?->isRole() && $this->contains($child, $item, [...$seen, $role])) {
                return true;
            }
        }

        return false;
    }
}
