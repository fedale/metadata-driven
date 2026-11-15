<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Infrastructure\Workspace\ConnectionCurrentWorkspace;
use App\Shared\Infrastructure\Workspace\WorkspaceProvisioner;
use App\Shared\Infrastructure\Workspace\WorkspaceSwitcher;
use App\Shared\Workspace\Workspace;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A second workspace next to the installation's first, in the test's transaction (schemas
 * included: PostgreSQL rolls DDL back too).
 */
trait CreatesWorkspaces
{
    /**
     * Created in the system session; the test is back in the workspace it was in.
     */
    private static function createWorkspace(ContainerInterface $container, string $slug, string $name): Workspace
    {
        $switcher = $container->get(WorkspaceSwitcher::class);
        $previous = $container->get(ConnectionCurrentWorkspace::class)->workspace();
        $switcher->system();
        try {
            return $container->get(WorkspaceProvisioner::class)->create($slug, $name);
        } finally {
            null === $previous ? $switcher->system() : $switcher->switchTo($previous);
        }
    }

    private static function useWorkspace(ContainerInterface $container, Workspace $workspace): void
    {
        $container->get(WorkspaceSwitcher::class)->switchTo($workspace);
    }
}
