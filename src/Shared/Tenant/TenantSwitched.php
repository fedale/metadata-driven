<?php

declare(strict_types=1);

namespace App\Shared\Tenant;

/**
 * The database session now works for another tenant, or for none. Whatever keeps a tenant's
 * data in memory for the rest of a request (a metadata snapshot, translations) forgets it.
 */
final readonly class TenantSwitched
{
    public function __construct(public ?Tenant $tenant)
    {
    }
}
