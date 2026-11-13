<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * The RBAC items the application checks, created in the current tenant: access:sync, and a new
 * tenant's first step.
 */
interface AccessSetupInterface
{
    /**
     * @return list<string> the items created: those missing only
     */
    public function syncAll(): array;
}
