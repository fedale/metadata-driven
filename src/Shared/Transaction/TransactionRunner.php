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
}
