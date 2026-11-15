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
 * Lets a user sign in to a workspace. A workspace command: it runs in the system session, the
 * workspace named by its argument.
 */
#[AsCommand(name: 'workspace:member:add', description: 'Let a user sign in to a workspace')]
final class AddWorkspaceMemberConsoleCommand
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
            $added = $this->users->addToWorkspace($email, $found->id);
        } catch (UserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $added
            ? $io->success(\sprintf('%s may sign in to %s. Give them a role there: rbac:assign --workspace=%s %s <role>', $email, $workspace, $workspace, $email))
            : $io->note(\sprintf('%s is a member of %s already.', $email, $workspace));

        return Command::SUCCESS;
    }
}
