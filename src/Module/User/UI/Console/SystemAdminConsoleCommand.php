<?php

declare(strict_types=1);

namespace App\Module\User\UI\Console;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The system admins of the installation, who may enter its console (console.<base host>). From the
 * command line only, so that no page can make a system admin: a stolen console session makes no
 * more of them. A workspace command: it runs as the system.
 */
#[AsCommand(name: 'system:admin', description: 'List, grant or revoke the system admins who may enter the system console')]
final class SystemAdminConsoleCommand
{
    public function __construct(
        private readonly UserManagerInterface $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('list, grant or revoke')] string $action = 'list',
        #[Argument('The user\'s email (grant, revoke)')] ?string $email = null,
    ): int {
        if ('list' !== $action) {
            if (!\in_array($action, ['grant', 'revoke'], true) || null === $email) {
                $io->error('system:admin list, or system:admin grant|revoke <email>.');

                return Command::INVALID;
            }
            try {
                $this->users->setSystemAdmin($email, 'grant' === $action);
            } catch (UserException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(\sprintf('grant' === $action ? '%s is a system admin: they may enter the console.' : '%s is no longer a system admin; a session of theirs on the console ends.', $email));
        }

        $systemAdmins = $this->users->systemAdmins();
        [] === $systemAdmins ? $io->note('No system admin.') : $io->listing($systemAdmins);

        return Command::SUCCESS;
    }
}
