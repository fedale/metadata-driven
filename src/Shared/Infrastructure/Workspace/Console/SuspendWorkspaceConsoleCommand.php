<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace\Console;

use App\Shared\Infrastructure\Workspace\WorkspaceProvisioner;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Suspends a workspace: its requests answer 404, its commands refuse to run, its data stays.
 */
#[AsCommand(name: 'workspace:suspend', description: 'Suspend a workspace: its requests answer 404, its data stays')]
final class SuspendWorkspaceConsoleCommand
{
    public function __construct(
        private readonly WorkspaceRegistry $workspaces,
        private readonly WorkspaceProvisioner $provisioner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workspace\'s slug')] string $slug,
    ): int {
        $workspace = $this->workspaces->bySlug($slug);
        if (null === $workspace) {
            $io->error(\sprintf('There is no workspace "%s".', $slug));

            return Command::FAILURE;
        }
        $this->provisioner->suspend($workspace);
        $io->success(\sprintf('Workspace "%s" suspended: workspace:resume brings it back.', $slug));

        return Command::SUCCESS;
    }
}
