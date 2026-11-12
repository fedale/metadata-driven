<?php

declare(strict_types=1);

namespace App\Module\User\UI\Console;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Tenant\Tenants;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes a user out of a tenant: they can no longer sign in there, and a session of theirs ends
 * at its next request. A tenant command: it runs in the landlord's session.
 */
#[AsCommand(name: 'tenant:member:remove', description: 'Take a user out of a tenant')]
final class RemoveTenantMemberConsoleCommand
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly Tenants $tenants,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant\'s slug')] string $tenant,
        #[Argument('The user\'s email')] string $email,
    ): int {
        $found = $this->tenants->bySlug($tenant);
        if (null === $found) {
            $io->error(\sprintf('There is no tenant "%s".', $tenant));

            return Command::FAILURE;
        }
        try {
            $removed = $this->users->removeFromTenant($email, $found->id);
        } catch (UserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $removed
            ? $io->success(\sprintf('%s can no longer sign in to %s.', $email, $tenant))
            : $io->note(\sprintf('%s is no member of %s.', $email, $tenant));

        return Command::SUCCESS;
    }
}
