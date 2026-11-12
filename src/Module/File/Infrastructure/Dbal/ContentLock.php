<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;

/**
 * The lock of a stored content, by its storage key (StorageKeys: a tenant's content): a
 * PostgreSQL advisory lock held until the outermost transaction ends.
 *
 * The same bytes are stored once per tenant, so the GC may delete the bytes of a content only
 * while no core.file row of the tenant uses it. A store() of the same bytes skips writing them and adds a row, in a
 * transaction the GC cannot see yet: both take this lock, so the GC waits for that transaction
 * and then finds the row.
 */
final class ContentLock
{
    /**
     * Must run inside a transaction: outside one, the lock is released at once.
     */
    public static function acquire(Connection $connection, string $storageKey): void
    {
        $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$storageKey])->free();
    }
}
