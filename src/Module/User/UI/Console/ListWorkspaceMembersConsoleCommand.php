<?php

declare(strict_types=1);

namespace App\Module\User\UI\Console;

use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Workspace\Workspaces;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workspace:member:list', description: 'List the users who may sign in to a workspace')]
final class ListWorkspaceMembersConsoleCommand
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly Workspaces $workspaces,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workspace\'s slug')] string $workspace,
    ): int {
        $found = $this->workspaces->bySlug($workspace);
        if (null === $found) {
            $io->error(\sprintf('There is no workspace "%s".', $workspace));

            return Command::FAILURE;
        }
        $members = $this->users->membersOf($found->id);
        [] === $members
            ? $io->note(\sprintf('%s has no members.', $workspace))
            : $io->listing($members);

        return Command::SUCCESS;
    }
}
