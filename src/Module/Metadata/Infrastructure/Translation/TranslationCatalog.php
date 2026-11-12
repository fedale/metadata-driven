<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Translation;

use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Module\Metadata\Infrastructure\Cache\CachedMetadataRegistry;
use App\Module\Metadata\Infrastructure\Cache\MetadataRevision;
use App\Shared\Tenant\TenantSwitched;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The texts of a language, cached with the metadata snapshot (same pool, same tag): a change
 * of translations dispatches MetadataChanged, which drops both. The key carries the metadata
 * revision, as the snapshot's does: a translation is a metadata write (see MetadataRevision).
 *
 * As for the snapshot, inside a transaction the texts are read from the database, never cached.
 */
#[AsEventListener(event: MetadataChanged::class, method: 'reset')]
// Kept in memory for one tenant: forgotten when the request switches to another.
#[AsEventListener(event: TenantSwitched::class, method: 'reset')]
final class TranslationCatalog implements ResetInterface
{
    private const string CACHE_KEY = 'metadata_translations.v1.';
    private const string CACHE_TAG = 'metadata';

    /** @var array<string, array<string, string>> */
    private array $texts = [];

    public function __construct(
        private readonly TranslationRepository $translations,
        private readonly TagAwareCacheInterface $metadataCache,
        private readonly Connection $connection,
        private readonly MetadataRevision $revision,
    ) {
    }

    /**
     * @return array<string, string> by key
     */
    public function textsOf(string $locale): array
    {
        if ($this->connection->isTransactionActive()) {
            return $this->translations->textsOf($locale);
        }

        /** @var array<string, string> $texts */
        $texts = $this->texts[$locale] ??= $this->metadataCache->get(self::CACHE_KEY.$locale.'.'.$this->revision->current(), function (ItemInterface $item) use ($locale): array {
            $item->tag(self::CACHE_TAG)->expiresAfter(CachedMetadataRegistry::TTL);

            return $this->translations->textsOf($locale);
        });

        return $texts;
    }

    public function reset(): void
    {
        $this->texts = [];
    }
}
