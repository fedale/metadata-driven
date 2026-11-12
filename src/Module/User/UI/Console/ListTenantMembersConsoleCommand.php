<?php

declare(strict_types=1);

namespace App\Module\User\UI\Console;

use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Tenant\Tenants;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:member:list', description: 'List the users who may sign in to a tenant')]
final class ListTenantMembersConsoleCommand
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly Tenants $tenants,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant\'s slug')] string $tenant,
    ): int {
        $found = $this->tenants->bySlug($tenant);
        if (null === $found) {
            $io->error(\sprintf('There is no tenant "%s".', $tenant));

            return Command::FAILURE;
        }
        $members = $this->users->membersOf($found->id);
        [] === $members
            ? $io->note(\sprintf('%s has no members.', $tenant))
            : $io->listing($members);

        return Command::SUCCESS;
    }
}
