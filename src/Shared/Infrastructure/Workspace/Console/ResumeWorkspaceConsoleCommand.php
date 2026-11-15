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
 * Brings a suspended workspace back.
 */
#[AsCommand(name: 'workspace:resume', description: 'Bring a suspended workspace back')]
final class ResumeWorkspaceConsoleCommand
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
        $this->provisioner->resume($workspace);
        $io->success(\sprintf('Workspace "%s" active again.', $slug));

        return Command::SUCCESS;
    }
}
