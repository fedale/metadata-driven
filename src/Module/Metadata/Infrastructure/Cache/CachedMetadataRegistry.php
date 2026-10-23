<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Cache;

use App\Module\Metadata\Application\Registry\MetadataSnapshotLoader;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationReference;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Serves the metadata snapshot from a tag-aware cache pool, memoized per request.
 *
 * Inside a transaction the snapshot is always read from the database and never
 * cached: listeners reacting to a definition change (e.g. schema sync) must see the
 * uncommitted definitions, and a rolled back change must never reach the cache.
 * The cache is dropped once the change is committed (MetadataChanged).
 */
#[AsEventListener(event: MetadataChanged::class, method: 'invalidate')]
final class CachedMetadataRegistry implements MetadataRegistryInterface, ResetInterface
{
    // Bump the version whenever the shape of the DTOs changes: the pool outlives deploys
    // (cache:clear does not empty it), and an old serialized snapshot would not hydrate.
    private const string CACHE_KEY = 'metadata_snapshot.v5';
    private const string CACHE_TAG = 'metadata';

    /** @var array<string, ObjectDefinitionDto>|null */
    private ?array $snapshot = null;

    public function __construct(
        private readonly MetadataSnapshotLoader $loader,
        private readonly TagAwareCacheInterface $metadataCache,
        private readonly Connection $connection,
    ) {
    }

    public function getObject(string $name): ObjectDefinitionDto
    {
        return $this->snapshot()[$name] ?? throw ObjectDefinitionNotFound::named($name);
    }

    public function hasObject(string $name): bool
    {
        return isset($this->snapshot()[$name]);
    }

    public function allObjects(): array
    {
        return $this->snapshot();
    }

    public function incomingRelations(string $targetName): array
    {
        $references = [];
        foreach ($this->snapshot() as $object) {
            foreach ($object->fields as $field) {
                $relation = $field->relation();
                if (null !== $relation && $relation->target === $targetName) {
                    $references[] = new RelationReference($object, $field, $relation);
                }
            }
        }

        return $references;
    }

    public function invalidate(): void
    {
        $this->metadataCache->invalidateTags([self::CACHE_TAG]);
        $this->snapshot = null;
    }

    public function reset(): void
    {
        $this->snapshot = null;
    }

    /**
     * @return array<string, ObjectDefinitionDto>
     */
    private function snapshot(): array
    {
        if ($this->connection->isTransactionActive()) {
            return $this->loader->load();
        }

        /** @var array<string, ObjectDefinitionDto> $snapshot */
        $snapshot = $this->snapshot ??= $this->metadataCache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->tag(self::CACHE_TAG);

            return $this->loader->load();
        });

        return $snapshot;
    }
}
