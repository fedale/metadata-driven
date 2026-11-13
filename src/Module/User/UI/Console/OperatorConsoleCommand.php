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
 * The operators of the installation, who may enter its console (console.<base host>). From the
 * command line only, so that no page can make an operator: a stolen console session makes no
 * more of them. A tenant command: it runs as the landlord.
 */
#[AsCommand(name: 'tenant:operator', description: 'List, grant or revoke the operators who may enter the console of the installation')]
final class OperatorConsoleCommand
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
                $io->error('tenant:operator list, or tenant:operator grant|revoke <email>.');

                return Command::INVALID;
            }
            try {
                $this->users->setOperator($email, 'grant' === $action);
            } catch (UserException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success(\sprintf('grant' === $action ? '%s is an operator: they may enter the console.' : '%s is no longer an operator; a session of theirs on the console ends.', $email));
        }

        $operators = $this->users->operators();
        [] === $operators ? $io->note('No operator.') : $io->listing($operators);

        return Command::SUCCESS;
    }
}
