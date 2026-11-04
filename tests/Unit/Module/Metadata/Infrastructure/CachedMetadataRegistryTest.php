<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Metadata\Infrastructure;

use App\Module\Metadata\Application\Registry\FormSnapshotLoader;
use App\Module\Metadata\Application\Registry\MetadataSnapshotLoader;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Domain\Model\FormDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\FormDefinitionRepository;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Module\Metadata\Infrastructure\Cache\CachedMetadataRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Uid\Uuid;

final class CachedMetadataRegistryTest extends TestCase
{
    /**
     * A setting added to a field type must reach the fields created before it, even when the
     * snapshot is already cached: the cache would otherwise serve settings without it.
     */
    public function testASettingAddedToATypeRenewsTheCachedSnapshot(): void
    {
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $before = $this->registry($pool, ['display' => 'checkbox']);
        self::assertSame(['display' => 'checkbox'], $before->getObject('pet')->field('vaccinated')->settings);

        // The same cache, after a deploy that gave the boolean type a "default" setting.
        $after = $this->registry($pool, ['display' => 'checkbox', 'default' => false]);
        self::assertSame(['display' => 'checkbox', 'default' => false], $after->getObject('pet')->field('vaccinated')->settings);
    }

    public function testTheSnapshotIsCachedWhileTheTypesDoNotChange(): void
    {
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $this->registry($pool, ['display' => 'checkbox'])->getObject('pet');

        $objects = $this->createMock(ObjectDefinitionRepository::class);
        $objects->expects(self::never())->method('findAll');
        $registry = new CachedMetadataRegistry(
            new MetadataSnapshotLoader($objects, $this->catalog(['display' => 'checkbox'])),
            new FormSnapshotLoader($this->noForms()),
            $pool,
            $this->createStub(Connection::class),
            $this->catalog(['display' => 'checkbox']),
        );
        self::assertTrue($registry->hasObject('pet'));
    }

    /**
     * @param array<string, mixed> $booleanDefaults
     */
    private function registry(TagAwareAdapter $pool, array $booleanDefaults): CachedMetadataRegistry
    {
        $pet = new ObjectDefinition(Uuid::v7(), 'pet', 'Pet', 'Pets');
        $pet->addField(Uuid::v7(), 'vaccinated', 'boolean', 'Vaccinated', false, false, []);
        $objects = $this->createStub(ObjectDefinitionRepository::class);
        $objects->method('findAll')->willReturn([$pet]);
        $catalog = $this->catalog($booleanDefaults);

        return new CachedMetadataRegistry(
            new MetadataSnapshotLoader($objects, $catalog),
            new FormSnapshotLoader($this->noForms()),
            $pool,
            $this->createStub(Connection::class),
            $catalog,
        );
    }

    private function noForms(): FormDefinitionRepository
    {
        return new class implements FormDefinitionRepository {
            public function save(FormDefinition $form): void
            {
            }

            public function remove(FormDefinition $form): void
            {
            }

            public function findByName(string $name): ?FormDefinition
            {
                return null;
            }

            public function findAll(): array
            {
                return [];
            }
        };
    }

    /**
     * @param array<string, mixed> $booleanDefaults
     */
    private function catalog(array $booleanDefaults): FieldTypeCatalogInterface
    {
        return new readonly class($booleanDefaults) implements FieldTypeCatalogInterface {
            /**
             * @param array<string, mixed> $booleanDefaults
             */
            public function __construct(private array $booleanDefaults)
            {
            }

            public function supports(string $type): bool
            {
                return 'boolean' === $type;
            }

            public function names(): array
            {
                return ['boolean'];
            }

            public function defaultSettings(string $type): array
            {
                return 'boolean' === $type ? $this->booleanDefaults : [];
            }

            public function fixedSettings(string $type): array
            {
                return [];
            }

            public function conditionValues(string $type, array $settings): ?array
            {
                return 'boolean' === $type ? ['true', 'false'] : null;
            }

            public function isWriteOnly(string $type): bool
            {
                return false;
            }

            public function supportsUnique(string $type): bool
            {
                return true;
            }

            public function isSortable(string $type): bool
            {
                return true;
            }

            public function resolveSettings(string $type, array $settings): array
            {
                return $settings + $this->defaultSettings($type);
            }
        };
    }
}
