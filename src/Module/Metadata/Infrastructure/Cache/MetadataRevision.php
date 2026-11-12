<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Cache;

use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Shared\Database\TenantScope;
use App\Shared\Tenant\TenantSwitched;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The part of every metadata cache key that ties a cached item to one tenant of one database and
 * one state of its metadata: the instance's id and the metadata revision, the current tenant's
 * row of core.metadata_state. Each tenant has an instance id of its own, so two tenants never
 * share a cached item either.
 *
 * Triggers bump the revision in the transaction of every write to the metadata tables, so a
 * change made on one server, or from the console, renews the keys everywhere the database is
 * read, whatever cache each server keeps; and two databases never share a cached item. It is
 * read once per request (one row), and again after a change (MetadataChanged).
 *
 * Read before the item it keys: a change committed in between is then cached under the old
 * key, which no one reads any more, never the old metadata under the new key.
 */
#[AsEventListener(event: MetadataChanged::class, method: 'reset')]
// Kept in memory for one tenant: forgotten when the request switches to another.
#[AsEventListener(event: TenantSwitched::class, method: 'reset')]
final class MetadataRevision implements ResetInterface
{
    private ?string $current = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * "<instance>.<revision>", to append to a cache key.
     */
    public function current(): string
    {
        if (null === $this->current) {
            $state = $this->connection->fetchAssociative(
                \sprintf('SELECT instance, revision FROM core.metadata_state WHERE tenant_id = %s', TenantScope::CURRENT),
            );
            $instance = false === $state ? null : $state['instance'];
            $revision = false === $state ? null : $state['revision'];
            if (!\is_string($instance) || !(\is_int($revision) || \is_string($revision))) {
                throw new \LogicException('core.metadata_state has no row for the current tenant: run the migrations, or select a tenant.');
            }
            $this->current = $instance.'.'.$revision;
        }

        return $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
