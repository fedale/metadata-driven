<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sets every new connection's session to its baseline: no tenant, or the fallback tenant of
 * app.tenant.fallback (the tests', which have no request or command to name one). A request
 * or a command then selects its tenant through TenantSwitcher. The landlord's session instead,
 * when the process selected it first (TenantSessionState): a migration opens its connection
 * after its command chose the landlord.
 *
 * A connection opened again (after close(), or a lost one) is back to the baseline: in
 * production none, so what follows sees no tenant's rows instead of the wrong ones.
 *
 * Set here rather than in DATABASE_URL: pdo_pgsql drops the libpq "options" parameter, and the
 * Symfony CLI replaces DATABASE_URL with the one of the Docker container.
 */
final class TenantSessionMiddleware implements Middleware
{
    public function __construct(
        private readonly TenantSessionState $state,
        #[Autowire('%app.tenant.fallback%')]
        private readonly string $fallback,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        $baseline = TenantSwitcher::baseline($this->fallback);

        return new class($driver, $this->state, $baseline) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly TenantSessionState $state, private readonly string $baseline)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec($this->state->isLandlord() ? TenantSessionSql::landlord() : $this->baseline);

                return $connection;
            }
        };
    }
}
