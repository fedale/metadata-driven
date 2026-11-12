<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant\Console;

use App\Shared\Infrastructure\Tenant\TenantProvisioner;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Suspends a tenant: its requests answer 404, its commands refuse to run, its data stays.
 */
#[AsCommand(name: 'tenant:suspend', description: 'Suspend a tenant: its requests answer 404, its data stays')]
final class SuspendTenantConsoleCommand
{
    public function __construct(
        private readonly TenantRegistry $tenants,
        private readonly TenantProvisioner $provisioner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant\'s slug')] string $slug,
    ): int {
        $tenant = $this->tenants->bySlug($slug);
        if (null === $tenant) {
            $io->error(\sprintf('There is no tenant "%s".', $slug));

            return Command::FAILURE;
        }
        $this->provisioner->suspend($tenant);
        $io->success(\sprintf('Tenant "%s" suspended: tenant:resume brings it back.', $slug));

        return Command::SUCCESS;
    }
}
