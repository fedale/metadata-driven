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
 * Brings a suspended tenant back.
 */
#[AsCommand(name: 'tenant:resume', description: 'Bring a suspended tenant back')]
final class ResumeTenantConsoleCommand
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
        $this->provisioner->resume($tenant);
        $io->success(\sprintf('Tenant "%s" active again.', $slug));

        return Command::SUCCESS;
    }
}
