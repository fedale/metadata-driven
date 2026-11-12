<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Database\TenantScope;
use App\Shared\Tenant\CurrentTenant;
use App\Shared\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The current tenant as the database session knows it: app.tenant_id, the setting the insert
 * trigger, the tenant filter and the policies read. Taking it from there rather than keeping an
 * id of its own means PHP and SQL can't disagree on the tenant.
 *
 * Read once (one row of core.tenant), and again after reset(): TenantSwitcher resets it on
 * every switch, the kernel between two requests of a worker.
 */
final class ConnectionCurrentTenant implements CurrentTenant, ResetInterface
{
    private Tenant|false|null $tenant = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly TenantRegistry $registry,
    ) {
    }

    public function isSelected(): bool
    {
        return null !== $this->tenant();
    }

    public function id(): string
    {
        return $this->selected()->id;
    }

    public function dataSchema(): string
    {
        return $this->selected()->dataSchema;
    }

    /**
     * The current tenant's row, null when none is selected.
     */
    public function tenant(): ?Tenant
    {
        if (null === $this->tenant) {
            $id = $this->connection->fetchOne(\sprintf('SELECT %s::text', TenantScope::CURRENT));
            $this->tenant = (\is_string($id) ? $this->registry->byId($id) : null) ?? false;
        }

        return false === $this->tenant ? null : $this->tenant;
    }

    public function reset(): void
    {
        $this->tenant = null;
    }

    private function selected(): Tenant
    {
        return $this->tenant() ?? throw new \LogicException('No tenant is selected: the database session has no app.tenant_id naming a row of core.tenant.');
    }
}
