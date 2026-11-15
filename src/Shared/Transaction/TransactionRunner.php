<?php

declare(strict_types=1);

namespace App\Shared\Transaction;

interface TransactionRunner
{
    /**
     * Runs the operation in a database transaction: committed when it returns,
     * rolled back when it throws. Nested calls join the outer transaction.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;

    /**
     * Runs $done once the work of the current transaction is settled: when the outermost
     * transaction ends, with true if it was committed and what was done in the current one is
     * kept, false otherwise (rolled back, or a nested transaction rolled back to its savepoint).
     * With no transaction open, $done runs at once, with true.
     *
     * $done runs outside any transaction: what it writes is committed on its own.
     *
     * @param callable(bool $committed): void $done
     */
    public function whenDone(callable $done): void;
}
