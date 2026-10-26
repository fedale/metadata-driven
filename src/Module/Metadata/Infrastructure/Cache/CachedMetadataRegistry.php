<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Cache;

use App\Module\Metadata\Application\Registry\FormSnapshotLoader;
use App\Module\Metadata\Application\Registry\MetadataSnapshotLoader;
use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationReference;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\FormDefinitionNotFound;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
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
    // The key also carries a fingerprint of the field types' default settings: the snapshot
    // merges them into the stored settings, so a setting added to a type renews it by itself.
    private const string CACHE_KEY = 'metadata_snapshot.v6';
    private const string FORMS_CACHE_KEY = 'metadata_forms.v3';
    private const string CACHE_TAG = 'metadata';

    /** @var array<string, ObjectDefinitionDto>|null */
    private ?array $snapshot = null;
    /** @var array<string, FormDefinitionDto>|null */
    private ?array $forms = null;
    private ?string $fingerprint = null;

    public function __construct(
        private readonly MetadataSnapshotLoader $loader,
        private readonly FormSnapshotLoader $formLoader,
        private readonly TagAwareCacheInterface $metadataCache,
        private readonly Connection $connection,
        private readonly FieldTypeCatalogInterface $fieldTypes,
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

    public function getForm(string $name): FormDefinitionDto
    {
        return $this->forms()[$name] ?? throw FormDefinitionNotFound::named($name);
    }

    public function hasForm(string $name): bool
    {
        return isset($this->forms()[$name]);
    }

    public function allForms(): array
    {
        return $this->forms();
    }

    public function invalidate(): void
    {
        $this->metadataCache->invalidateTags([self::CACHE_TAG]);
        $this->reset();
    }

    public function reset(): void
    {
        $this->snapshot = null;
        $this->forms = null;
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
        $snapshot = $this->snapshot ??= $this->metadataCache->get(self::CACHE_KEY.'.'.$this->fingerprint(), function (ItemInterface $item): array {
            $item->tag(self::CACHE_TAG);

            return $this->loader->load();
        });

        return $snapshot;
    }

    /**
     * Kept apart from the objects, under the same tag: the forms change on their own.
     *
     * @return array<string, FormDefinitionDto>
     */
    private function forms(): array
    {
        if ($this->connection->isTransactionActive()) {
            return $this->formLoader->load();
        }

        /** @var array<string, FormDefinitionDto> $forms */
        $forms = $this->forms ??= $this->metadataCache->get(self::FORMS_CACHE_KEY, function (ItemInterface $item): array {
            $item->tag(self::CACHE_TAG);

            return $this->formLoader->load();
        });

        return $forms;
    }

    /**
     * Changes whenever a field type gains, loses or changes the default of a setting.
     */
    private function fingerprint(): string
    {
        if (null === $this->fingerprint) {
            $defaults = [];
            foreach ($this->fieldTypes->names() as $type) {
                $defaults[$type] = $this->fieldTypes->defaultSettings($type);
            }
            ksort($defaults);
            $this->fingerprint = substr(hash('xxh128', serialize($defaults)), 0, 16);
        }

        return $this->fingerprint;
    }
}
