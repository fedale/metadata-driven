<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Database\CoreSchema;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Puts the core schema first in the search_path of every connection, so that unqualified names
 * (the bundles' entities, their migrations) resolve to core instead of public. Set here rather
 * than in DATABASE_URL: pdo_pgsql drops the libpq "options" parameter, and the Symfony CLI
 * replaces DATABASE_URL with the one of the Docker container. public stays as a fallback for the
 * extensions installed there (pg_trgm).
 */
final class CoreSearchPathMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec(sprintf('SET search_path TO %s, public', CoreSchema::NAME));

                return $connection;
            }
        };
    }
}
