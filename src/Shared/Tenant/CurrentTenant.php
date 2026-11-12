<?php

declare(strict_types=1);

namespace App\Shared\Tenant;

/**
 * The tenant the current request or command works for (see docs/internal/multi-tenancy-plan.md).
 *
 * Its definitions, files and templates are rows of the core tables carrying its id
 * ({@see \App\Shared\Database\TenantScope}); its records are the tables of a schema of its own,
 * {@see dataSchema()}.
 */
interface CurrentTenant
{
    /**
     * The tenant's id, a UUID.
     *
     * @throws \LogicException when no tenant is selected
     */
    public function id(): string;

    /**
     * The PostgreSQL schema holding the tenant's record tables, unquoted.
     *
     * @return non-empty-string
     *
     * @throws \LogicException when no tenant is selected
     */
    public function dataSchema(): string;
}
