<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Manager\SettingsManager;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The current tenant for fedale/setting-bundle, whose tenant ids are integers: the tenant's
 * number. The bundle reads the global layer (0) under every tenant's own, so a setting saved
 * before tenants existed still applies to all of them. With no tenant, the global layer.
 */
#[AsAlias(TenantProviderInterface::class)]
final class SettingsTenantProvider implements TenantProviderInterface
{
    public function __construct(private readonly ConnectionCurrentTenant $current)
    {
    }

    public function getCurrentTenantId(): int
    {
        $tenant = $this->current->tenant();

        return null === $tenant ? SettingsManager::GLOBAL_TENANT : $tenant->number;
    }
}
