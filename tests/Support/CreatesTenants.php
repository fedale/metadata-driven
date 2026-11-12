<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Infrastructure\Tenant\ConnectionCurrentTenant;
use App\Shared\Infrastructure\Tenant\TenantProvisioner;
use App\Shared\Infrastructure\Tenant\TenantSwitcher;
use App\Shared\Tenant\Tenant;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A second tenant next to the installation's first, in the test's transaction (schemas
 * included: PostgreSQL rolls DDL back too).
 */
trait CreatesTenants
{
    /**
     * Created in the landlord's session; the test is back in the tenant it was in.
     */
    private static function createTenant(ContainerInterface $container, string $slug, string $name): Tenant
    {
        $switcher = $container->get(TenantSwitcher::class);
        $previous = $container->get(ConnectionCurrentTenant::class)->tenant();
        $switcher->landlord();
        try {
            return $container->get(TenantProvisioner::class)->create($slug, $name);
        } finally {
            null === $previous ? $switcher->landlord() : $switcher->switchTo($previous);
        }
    }

    private static function useTenant(ContainerInterface $container, Tenant $tenant): void
    {
        $container->get(TenantSwitcher::class)->switchTo($tenant);
    }
}
