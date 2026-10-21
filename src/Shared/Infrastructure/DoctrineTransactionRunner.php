<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Transaction\TransactionRunner;
use Doctrine\DBAL\Connection;

/**
 * Runs on the DBAL connection shared with the ORM. Unlike EntityManager::wrapInTransaction(),
 * a domain exception (a refused delete, an invalid definition) only rolls back: it does not
 * close the entity manager for the rest of the request.
 */
final class DoctrineTransactionRunner implements TransactionRunner
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }
}
