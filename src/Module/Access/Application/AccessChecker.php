<?php

declare(strict_types=1);

namespace App\Module\Access\Application;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use Fedale\RbacBundle\Contract\AccessManagerInterface;

/**
 * Asks the RBAC graph: assignments, role hierarchy and rules, the super admin short-circuit.
 */
final class AccessChecker implements AccessCheckerInterface
{
    public function __construct(
        private readonly AccessManagerInterface $access,
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
}
