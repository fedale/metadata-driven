<?php

declare(strict_types=1);

namespace App\Shared\Tenant;

/**
 * Work done in a tenant's session from the landlord's: the steps that are a tenant's (its
 * permissions, its roles) when the console of the installation creates or manages it.
 */
interface TenantSessions
{
    /**
     * Runs $work in the tenant's session, then goes back to the session it was in.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function within(Tenant $tenant, callable $work): mixed;
}
