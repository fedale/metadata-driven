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
 * Lets a user sign in to a tenant. A tenant command: it runs in the landlord's session, the
 * tenant named by its argument.
 */
#[AsCommand(name: 'tenant:member:add', description: 'Let a user sign in to a tenant')]
final class AddTenantMemberConsoleCommand
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
            $added = $this->users->addToTenant($email, $found->id);
        } catch (UserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $added
            ? $io->success(\sprintf('%s may sign in to %s. Give them a role there: rbac:assign --tenant=%s %s <role>', $email, $tenant, $tenant, $email))
            : $io->note(\sprintf('%s is a member of %s already.', $email, $tenant));

        return Command::SUCCESS;
    }
}
