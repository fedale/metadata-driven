<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Manager\SettingsManager;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The current workspace for fedale/setting-bundle, whose tenant ids are integers: the workspace's
 * number. The bundle reads the global layer (0) under every workspace's own, so a setting saved
 * before workspaces existed still applies to all of them. With no workspace, the global layer.
 */
#[AsAlias(TenantProviderInterface::class)]
final class SettingsWorkspaceProvider implements TenantProviderInterface
{
    public function __construct(private readonly ConnectionCurrentWorkspace $current)
    {
    }

    public function getCurrentTenantId(): int
    {
        $workspace = $this->current->workspace();

        return null === $workspace ? SettingsManager::GLOBAL_TENANT : $workspace->number;
    }
}
