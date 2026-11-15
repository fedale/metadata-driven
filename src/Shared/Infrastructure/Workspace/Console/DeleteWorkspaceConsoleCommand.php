<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace\Console;

use App\Shared\Infrastructure\Workspace\WorkspaceProvisioner;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes a workspace and everything it holds, once its slug is typed back (or given to
 * --confirm, without a terminal). Irreversible: back the database up first.
 */
#[AsCommand(name: 'workspace:delete', description: 'Delete a workspace with its records, roles and definitions: irreversible')]
final class DeleteWorkspaceConsoleCommand
{
    public function __construct(
        private readonly WorkspaceRegistry $workspaces,
        private readonly WorkspaceProvisioner $provisioner,
        #[Autowire('%app.workspace.default%')]
        private readonly string $default,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workspace\'s slug')] string $slug,
        #[Option('The slug again, to confirm without a terminal')] ?string $confirm = null,
    ): int {
        $workspace = $this->workspaces->bySlug($slug);
        if (null === $workspace) {
            $io->error(\sprintf('There is no workspace "%s".', $slug));

            return Command::FAILURE;
        }
        if ($slug === $this->default) {
            $io->error(\sprintf('"%s" is the default workspace (WORKSPACE_DEFAULT): name another one there first.', $slug));

            return Command::FAILURE;
        }
        $io->warning(\sprintf('Workspace "%s" will be deleted with its records, roles, definitions and files: this cannot be undone.', $slug));
        // Without a terminal, ask() answers null: not confirmed.
        $confirm ??= $io->ask('Type its slug to confirm');
        if ($confirm !== $slug) {
            $io->error('Not confirmed: nothing was deleted.');

            return Command::FAILURE;
        }

        $this->provisioner->delete($workspace);
        $io->success(\sprintf('Workspace "%s" deleted. The bytes of its files go at the next metadata:files:gc.', $slug));

        return Command::SUCCESS;
    }
}
