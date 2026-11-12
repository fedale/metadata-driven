<?php

declare(strict_types=1);

namespace App\Shared\Tenant;

/**
 * A row of core.tenant, the tenant registry.
 */
final readonly class Tenant
{
    /**
     * @param string           $id         a UUID
     * @param int              $number     a small integer of its own, for fedale/setting-bundle, whose tenant ids are integers
     * @param non-empty-string $dataSchema the schema of its record tables
     * @param non-empty-string $rbacSchema the schema of its fedale/rbac-bundle tables, until the bundle knows about tenants
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public int $number,
        public string $dataSchema,
        public string $rbacSchema,
        public bool $active,
    ) {
    }
}
