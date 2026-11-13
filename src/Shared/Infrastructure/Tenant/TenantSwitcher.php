<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Tenant\Tenant;
use App\Shared\Tenant\TenantSessions;
use App\Shared\Tenant\TenantSwitched;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Points the database session at a tenant (switchTo()), or back at its baseline (leave()):
 * no tenant, or the fallback one of app.tenant.fallback (the tests').
 *
 * What a switch leaves behind is forgotten: the entities the ORM holds, the current tenant's
 * cached row, and, through TenantSwitched, the metadata kept in memory for the request.
 * Between two requests of a worker, reset() goes back to the baseline: a request that names no
 * tenant (a 404 before anything runs) can't read the previous one's.
 */
final class TenantSwitcher implements ResetInterface, TenantSessions
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectionCurrentTenant $current,
        private readonly TenantSessionState $state,
        private readonly EventDispatcherInterface $events,
        #[Autowire('%app.tenant.fallback%')]
        private readonly string $fallback,
    ) {
    }

    public function within(Tenant $tenant, callable $work): mixed
    {
        $previous = $this->current->tenant();
        $landlord = $this->state->isLandlord();
        $this->switchTo($tenant);
        try {
            return $work();
        } finally {
            if (null !== $previous) {
                $this->switchTo($previous);
            } elseif ($landlord) {
                $this->landlord();
            } else {
                $this->leave();
            }
        }
    }

    /**
     * The SQL putting a session at its baseline.
     */
    public static function baseline(string $fallback): string
    {
        return '' === $fallback ? TenantSessionSql::none() : TenantSessionSql::bySlug($fallback);
    }

    public function switchTo(Tenant $tenant): void
    {
        $this->state->setLandlord(false);
        $this->connection->executeStatement(TenantSessionSql::select($tenant));
        $this->forget($tenant);
    }

    /**
     * The landlord's session, whatever the baseline: no tenant, and the row-level security
     * policies let it see and write every tenant's rows (the migrations, the tenant commands,
     * creating a tenant).
     */
    public function landlord(): void
    {
        // Never opens a connection: a command may run without a database, or before it exists
        // (doctrine:database:create). One opened later is the landlord's too (the middleware
        // reads the state).
        $this->state->setLandlord(true);
        if ($this->connection->isConnected()) {
            $this->connection->executeStatement(TenantSessionSql::landlord());
        }
        $this->forget(null);
    }

    /**
     * Back to the baseline: no tenant, or the fallback one (the tests').
     */
    public function leave(): void
    {
        $this->state->setLandlord(false);
        $this->toBaseline();
        $this->forget(null);
    }

    public function reset(): void
    {
        $this->state->setLandlord(false);
        $this->toBaseline();
        $this->current->reset();
    }

    private function toBaseline(): void
    {
        // A connection not open yet gets its baseline when it opens: no need to open it (a
        // command such as cache:clear may run without a database).
        if ($this->connection->isConnected()) {
            $this->connection->executeStatement(self::baseline($this->fallback));
        }
    }

    private function forget(?Tenant $tenant): void
    {
        $this->entityManager->clear();
        $this->current->reset();
        $this->events->dispatch(new TenantSwitched($tenant));
    }
}
