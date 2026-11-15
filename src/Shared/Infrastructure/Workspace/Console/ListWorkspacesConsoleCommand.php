<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace\Console;

use App\Shared\Workspace\WorkspaceAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Every workspace, with what it holds. A workspace command: the system session sees every
 * workspace's rows.
 */
#[AsCommand(name: 'workspace:list', description: 'List the workspaces: slug, name, status, members, objects, schemas')]
final class ListWorkspacesConsoleCommand
{
    public function __construct(
        private readonly WorkspaceAdministration $workspaces,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->workspaces->overview() as ['workspace' => $workspace, 'members' => $members, 'objects' => $objects]) {
            $rows[] = [
                $workspace->slug,
                $workspace->name,
                $workspace->active ? 'active' : 'suspended',
                $members,
                $objects,
                $workspace->dataSchema.', '.$workspace->rbacSchema,
            ];
        }
        $io->table(['Slug', 'Name', 'Status', 'Members', 'Objects', 'Schemas'], $rows);

        return Command::SUCCESS;
    }
}
