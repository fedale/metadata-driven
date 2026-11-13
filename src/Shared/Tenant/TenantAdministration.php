<?php

declare(strict_types=1);

namespace App\Shared\Tenant;

/**
 * Creates and manages tenants, as the landlord: the console of the installation and the tenant:*
 * commands.
 */
interface TenantAdministration
{
    /**
     * @throws \InvalidArgumentException a slug that is malformed, reserved or taken
     */
    public function create(string $slug, string $name): Tenant;

    public function suspend(Tenant $tenant): void;

    public function resume(Tenant $tenant): void;

    /**
     * Irreversible: its records, roles, definitions, members and files.
     */
    public function delete(Tenant $tenant): void;

    /**
     * @return list<array{tenant: Tenant, members: int, objects: int}> by slug
     */
    public function overview(): array;
}
