<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * The RBAC items the application checks, created in the current workspace: access:sync, and a new
 * workspace's first step.
 */
interface AccessSetupInterface
{
    /**
     * @return list<string> the items created: those missing only
     */
    public function syncAll(): array;
}
