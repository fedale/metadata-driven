<?php

declare(strict_types=1);

namespace App\Shared\Tenant;

/**
 * The tenant registry, core.tenant: readable by every module, whatever the current tenant.
 */
interface Tenants
{
    public function bySlug(string $slug): ?Tenant;

    public function byId(string $id): ?Tenant;

    /**
     * @return list<Tenant> by slug
     */
    public function all(): array;
}
