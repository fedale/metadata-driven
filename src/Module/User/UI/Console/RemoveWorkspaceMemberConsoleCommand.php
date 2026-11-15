<?php

declare(strict_types=1);

namespace App\Module\User\UI\Console;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Workspace\Workspaces;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes a user out of a workspace: they can no longer sign in there, and a session of theirs ends
 * at its next request. A workspace command: it runs in the system session.
 */
#[AsCommand(name: 'workspace:member:remove', description: 'Take a user out of a workspace')]
final class RemoveWorkspaceMemberConsoleCommand
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly Workspaces $workspaces,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workspace\'s slug')] string $workspace,
        #[Argument('The user\'s email')] string $email,
    ): int {
        $found = $this->workspaces->bySlug($workspace);
        if (null === $found) {
            $io->error(\sprintf('There is no workspace "%s".', $workspace));

            return Command::FAILURE;
        }
        try {
            $removed = $this->users->removeFromWorkspace($email, $found->id);
        } catch (UserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $removed
            ? $io->success(\sprintf('%s can no longer sign in to %s.', $email, $workspace))
            : $io->note(\sprintf('%s is no member of %s.', $email, $workspace));

        return Command::SUCCESS;
    }
}
