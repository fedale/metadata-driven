<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant\Console;

use App\Shared\Infrastructure\Tenant\TenantRegistry;
use Doctrine\DBAL\Connection;
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
        private readonly TenantRegistry $tenants,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        /** @var array<string, int|string> $members */
        $members = $this->connection->fetchAllKeyValue('SELECT tenant_id, count(*) FROM core.tenant_membership GROUP BY tenant_id');
        /** @var array<string, int|string> $objects */
        $objects = $this->connection->fetchAllKeyValue('SELECT tenant_id, count(*) FROM core.metadata_object GROUP BY tenant_id');

        $rows = [];
        foreach ($this->tenants->all() as $tenant) {
            $rows[] = [
                $tenant->slug,
                $tenant->name,
                $tenant->active ? 'active' : 'suspended',
                (int) ($members[$tenant->id] ?? 0),
                (int) ($objects[$tenant->id] ?? 0),
                $tenant->dataSchema.', '.$tenant->rbacSchema,
            ];
        }
        $io->table(['Slug', 'Name', 'Status', 'Members', 'Objects', 'Schemas'], $rows);

        return Command::SUCCESS;
    }
}
