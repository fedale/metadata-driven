<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Access;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a role gives, and what another member may give: the checks of a workflow's step acting
 * as a role (no way to more power).
 *
 *     ROLE_MANAGER  ⊃ ROLE_CLERK ⊃ p.read, p.write
 *                   ⊃ p.approve
 *     ROLE_AUDITOR  ⊃ p.read, p.audit
 */
final class RoleChecksTest extends KernelTestCase
{
    private AccessCheckerInterface $access;

    private RbacManagerInterface $rbac;

    protected function setUp(): void
    {
        $this->rbac = self::getContainer()->get(RbacManagerInterface::class);
        foreach (['p.read', 'p.write', 'p.approve', 'p.audit'] as $permission) {
            $this->rbac->addItem($permission, AuthItemType::PERMISSION);
        }
        foreach (['ROLE_MANAGER', 'ROLE_CLERK', 'ROLE_AUDITOR'] as $role) {
            $this->rbac->addItem($role, AuthItemType::ROLE);
        }
        $this->rbac->addItem(Permissions::SUPER_ADMIN_ROLE, AuthItemType::ROLE);
        foreach ([['ROLE_MANAGER', 'ROLE_CLERK'], ['ROLE_MANAGER', 'p.approve'], ['ROLE_CLERK', 'p.read'], ['ROLE_CLERK', 'p.write'], ['ROLE_AUDITOR', 'p.read'], ['ROLE_AUDITOR', 'p.audit']] as [$parent, $child]) {
            $this->rbac->addChild($parent, $child);
        }
        $this->access = self::getContainer()->get(AccessCheckerInterface::class);
    }

    public function testARoleGivesWhatItContainsThroughItsRoles(): void
    {
        self::assertTrue($this->access->roleGives('ROLE_MANAGER', 'p.write'));
        self::assertTrue($this->access->roleGives('ROLE_MANAGER', 'ROLE_CLERK'));
        self::assertTrue($this->access->roleGives('ROLE_CLERK', 'ROLE_CLERK'));
        self::assertFalse($this->access->roleGives('ROLE_CLERK', 'p.approve'));
        self::assertFalse($this->access->roleGives('ROLE_MANAGER', 'p.audit'));
        self::assertTrue($this->access->roleGives(Permissions::SUPER_ADMIN_ROLE, 'p.audit'));
        self::assertFalse($this->access->roleGives('ROLE_NOBODY', 'p.read'));
    }

    public function testACycleInTheHierarchyEnds(): void
    {
        $this->rbac->addItem('ROLE_LOOP', AuthItemType::ROLE);
        $this->rbac->addChild('ROLE_CLERK', 'ROLE_LOOP');
        $this->rbac->addChild('ROLE_LOOP', 'ROLE_CLERK');

        self::assertFalse($this->access->roleGives('ROLE_LOOP', 'p.approve'));
        self::assertTrue($this->access->roleGives('ROLE_LOOP', 'p.write'));
    }

    public function testAMemberGivesNoMoreThanTheyHold(): void
    {
        $this->rbac->assign('anna@example.com', 'ROLE_MANAGER');

        self::assertTrue($this->access->userCanGrant('anna@example.com', 'ROLE_MANAGER'));
        self::assertTrue($this->access->userCanGrant('anna@example.com', 'ROLE_CLERK'));
        self::assertTrue($this->access->userCanGrant('anna@example.com', 'p.approve'));
        // ROLE_AUDITOR gives p.audit, which she does not hold.
        self::assertFalse($this->access->userCanGrant('anna@example.com', 'ROLE_AUDITOR'));
        self::assertFalse($this->access->userCanGrant('anna@example.com', Permissions::SUPER_ADMIN_ROLE));

        $this->rbac->assign('anna@example.com', 'p.audit');
        self::assertTrue($this->access->userCanGrant('anna@example.com', 'ROLE_AUDITOR'));
    }

    public function testOnlyTheSuperAdminGivesTheSuperAdminRole(): void
    {
        $this->rbac->assign('root@example.com', Permissions::SUPER_ADMIN_ROLE);

        self::assertTrue($this->access->userCanGrant('root@example.com', Permissions::SUPER_ADMIN_ROLE));
        self::assertTrue($this->access->userCanGrant('root@example.com', 'ROLE_AUDITOR'));
    }

    public function testAMemberWithNothingAssignedGivesNothing(): void
    {
        $this->rbac->assign('anna@example.com', 'ROLE_MANAGER');
        $this->rbac->revoke('anna@example.com', 'ROLE_MANAGER');

        self::assertFalse($this->access->userCanGrant('anna@example.com', 'ROLE_CLERK'));
        self::assertFalse($this->access->userCanGrant('nobody@example.com', 'p.read'));
    }
}
