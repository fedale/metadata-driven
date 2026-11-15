<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use Fedale\RbacBundle\Contract\TenantContextInterface;

/**
 * The current tenant for fedale/rbac-bundle (tenancy.context in fedale_rbac.yaml): its id, a
 * UUID, which keys the bundle's cache and its graphs in memory. Each tenant has RBAC tables of
 * its own (its rbac_schema, in the search_path), so the bundle needs nothing else: its
 * assignments all stay in its 'global' tenant.
 *
 * With no tenant, 'global': the session reads the empty tables of no_tenant_rbac, and no tenant
 * id is ever 'global'.
 */
final class RbacTenantContext implements TenantContextInterface
{
    public function __construct(private readonly ConnectionCurrentTenant $current)
    {
    }

    public function getTenantId(): string
    {
        return $this->current->tenant()->id ?? self::GLOBAL;
    }
}
