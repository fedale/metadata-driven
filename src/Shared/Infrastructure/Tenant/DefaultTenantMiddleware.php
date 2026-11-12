<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Selects the installation's only tenant on every new connection, setting app.tenant_id.
 *
 * Phase 1 of the multi-tenancy plan: the tables carry a tenant_id, but there is one tenant,
 * created by the migration that added the column. Phase 2 replaces this with a tenant chosen
 * per request (by host) and per command (--tenant).
 *
 * A DO block, so that a database without core.tenant (a fresh one, before its migrations)
 * still connects: PL/pgSQL plans the query only when it runs it.
 */
final class DefaultTenantMiddleware implements Middleware
{
    // Public: the anonymous driver below is a class of its own, without access to private members.
    public const string SELECT_DEFAULT_TENANT = <<<'SQL'
        DO $$
        BEGIN
            IF to_regclass('core.tenant') IS NOT NULL THEN
                PERFORM set_config('app.tenant_id', COALESCE((SELECT id::text FROM core.tenant ORDER BY created_at, id LIMIT 1), ''), false);
            END IF;
        END
        $$
        SQL;

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec(DefaultTenantMiddleware::SELECT_DEFAULT_TENANT);

                return $connection;
            }
        };
    }
}
