<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant\Console;

use App\Shared\Tenant\TenantAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Every tenant, with what it holds. A tenant command: the landlord's session sees every
 * tenant's rows.
 */
#[AsCommand(name: 'tenant:list', description: 'List the tenants: slug, name, status, members, objects, schemas')]
final class ListTenantsConsoleCommand
{
    public function __construct(
        private readonly TenantAdministration $tenants,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->tenants->overview() as ['tenant' => $tenant, 'members' => $members, 'objects' => $objects]) {
            $rows[] = [
                $tenant->slug,
                $tenant->name,
                $tenant->active ? 'active' : 'suspended',
                $members,
                $objects,
                $tenant->dataSchema.', '.$tenant->rbacSchema,
            ];
        }
        $io->table(['Slug', 'Name', 'Status', 'Members', 'Objects', 'Schemas'], $rows);

        return Command::SUCCESS;
    }
}
