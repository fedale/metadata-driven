<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Transaction\TransactionRunner;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runs on the DBAL connection shared with the ORM. Unlike EntityManager::wrapInTransaction(),
 * a domain exception (a refused delete, an invalid definition) only rolls back: it does not
 * close the entity manager for the rest of the request.
 *
 * The transactions are counted here, not asked of the connection: the tests run inside a
 * transaction of their own (dama/doctrine-test-bundle), which is no transaction of the
 * application's.
 */
final class DoctrineTransactionRunner implements TransactionRunner, ResetInterface
{
    /**
     * The work deferred by each open transaction, the outermost first.
     *
     * @var list<list<callable(bool): void>>
     */
    private array $levels = [];

    /** @var list<callable(bool): void> deferred by a nested transaction rolled back: false */
    private array $rolledBack = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function run(callable $operation): mixed
    {
        $this->levels[] = [];
        try {
            $result = $this->connection->transactional(static fn (): mixed => $operation());
        } catch (\Throwable $e) {
            $this->rolledBack = [...$this->rolledBack, ...array_pop($this->levels)];
            if ([] === $this->levels) {
                $this->settle(false);
            }

            throw $e;
        }

        $done = array_pop($this->levels);
        if ([] !== $this->levels) {
            // Kept, for now: its fate is the outer transaction's.
            $this->levels[array_key_last($this->levels)] = [...$this->levels[array_key_last($this->levels)], ...$done];

            return $result;
        }
        $this->settle(true, $done);

        return $result;
    }

    public function whenDone(callable $done): void
    {
        if ([] === $this->levels) {
            $done(true);

            return;
        }
        $this->levels[array_key_last($this->levels)][] = $done;
    }

    public function reset(): void
    {
        $this->levels = [];
        $this->rolledBack = [];
    }

    /**
     * Runs the deferred work once the outermost transaction ended: every callable, even when one
     * throws (the first exception is thrown after the others ran).
     *
     * @param list<callable(bool): void> $committed
     */
    private function settle(bool $outcome, array $committed = []): void
    {
        $calls = [...array_map(static fn (callable $done): array => [$done, $outcome], $committed), ...array_map(static fn (callable $done): array => [$done, false], $this->rolledBack)];
        $this->rolledBack = [];
        $first = null;
        foreach ($calls as [$done, $committedHere]) {
            try {
                $done($committedHere);
            } catch (\Throwable $e) {
                $first ??= $e;
            }
        }
        if (null !== $first) {
            throw $first;
        }
    }
}
