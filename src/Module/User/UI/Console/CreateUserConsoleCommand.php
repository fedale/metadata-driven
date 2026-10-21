<?php

declare(strict_types=1);

namespace App\Module\User\UI\Console;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'user:create', description: 'Create a user who can sign in')]
final class CreateUserConsoleCommand
{
    public function __construct(
        private readonly UserManagerInterface $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email, used to sign in')] string $email,
        #[Option('First name')] ?string $firstName = null,
        #[Option('Last name')] ?string $lastName = null,
        #[Option('Password (asked, hidden, when omitted; avoid it in shared shells)')] ?string $password = null,
    ): int {
        $password ??= $io->askHidden('Password');
        if (!\is_string($password)) {
            $io->error('A password is required.');

            return Command::INVALID;
        }

        try {
            $id = $this->users->createUser($email, $password, $firstName, $lastName);
        } catch (UserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('User "%s" created (%s). Give them a role: rbac:assign %s <role>', $email, $id, mb_strtolower(trim($email))));

        return Command::SUCCESS;
    }
}
