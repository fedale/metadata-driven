<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant\Console;

use App\Shared\Infrastructure\Tenant\TenantProvisioner;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes a tenant and everything it holds, once its slug is typed back (or given to
 * --confirm, without a terminal). Irreversible: back the database up first.
 */
#[AsCommand(name: 'tenant:delete', description: 'Delete a tenant with its records, roles and definitions: irreversible')]
final class DeleteTenantConsoleCommand
{
    public function __construct(
        private readonly TenantRegistry $tenants,
        private readonly TenantProvisioner $provisioner,
        #[Autowire('%app.tenant.default%')]
        private readonly string $default,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant\'s slug')] string $slug,
        #[Option('The slug again, to confirm without a terminal')] ?string $confirm = null,
    ): int {
        $tenant = $this->tenants->bySlug($slug);
        if (null === $tenant) {
            $io->error(\sprintf('There is no tenant "%s".', $slug));

            return Command::FAILURE;
        }
        if ($slug === $this->default) {
            $io->error(\sprintf('"%s" is the default tenant (TENANT_DEFAULT): name another one there first.', $slug));

            return Command::FAILURE;
        }
        $io->warning(\sprintf('Tenant "%s" will be deleted with its records, roles, definitions and files: this cannot be undone.', $slug));
        // Without a terminal, ask() answers null: not confirmed.
        $confirm ??= $io->ask('Type its slug to confirm');
        if ($confirm !== $slug) {
            $io->error('Not confirmed: nothing was deleted.');

            return Command::FAILURE;
        }

        $this->provisioner->delete($tenant);
        $io->success(\sprintf('Tenant "%s" deleted. The bytes of its files go at the next metadata:files:gc.', $slug));

        return Command::SUCCESS;
    }
}
