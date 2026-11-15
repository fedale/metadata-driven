<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Package\Application\Command\ExportPackageCommand;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Domain\Exception\InvalidExport;
use App\Module\Package\Domain\Repository\PackageItemRepository;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\ExamplePackage;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\SeedRecords;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exports objects pointing at those of an installed package (acme/places 1.2.0, in
 * tests/Fixtures/packages): the export requires that package, and names its items by id.
 */
final class PackageRequiresExportTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->install(ExamplePackage::places());
    }

    public function testRequiresTheInstalledPackageItsObjectsPointAt(): void
    {
        $this->person();

        $package = $this->export(['person']);

        self::assertSame(['format' => 'metadata-driven/v2', 'acme/places' => '^1.2'], $package->manifest->spec['requires']);
        $person = self::document($package, 'objects/person.yaml');
        self::assertSame(['target' => 'acme.region'], self::settings($person, 'region'));
        self::assertSame(['target' => 'acme.province', 'dependsOn' => 'acme.person.region', 'dependsVia' => 'acme.province.region'], array_intersect_key(self::settings($person, 'province'), array_flip(['target', 'dependsOn', 'dependsVia'])));
        // Only its own items are recorded: the region stays acme/places'.
        foreach (self::getContainer()->get(PackageItemRepository::class)->itemsOf('acme/people') as $item) {
            self::assertStringStartsWith('acme.person', $item->getItemId());
        }
    }

    public function testKeepsTheConstraintItsInstalledVersionHas(): void
    {
        $this->install(ExamplePackage::people());

        $package = $this->export(['person'], version: '1.1.0');

        // acme/people 1.0.0 requires acme/places ^1.0, which 1.2.0 fits: not narrowed to ^1.2.
        self::assertSame(['format' => 'metadata-driven/v2', 'acme/places' => '^1.0'], $package->manifest->spec['requires']);
    }

    public function testWritesTheRecordsOfTheRequiredSeedsByTheirKeys(): void
    {
        $this->person();
        $this->record('person', ['email' => 'ada@example.com', 'region' => $this->id('region', '01'), 'province' => $this->id('province', 'TO')]);

        $package = $this->export(['person'], seeds: ['person' => 'email']);

        $rows = self::getContainer()->get(SeedRecords::class)->of($package, self::document($package, 'seeds/person.yaml'));
        self::assertSame([['email' => 'ada@example.com', 'region' => '01', 'province' => 'TO']], $rows);
    }

    public function testRefusesARecordTheRequiredPackageDoesNotSeed(): void
    {
        $this->person();
        $east = $this->record('region', ['code' => '03', 'name' => 'East']);
        $this->record('person', ['email' => 'ada@example.com', 'region' => $east]);

        $this->assertRefused(['person'], \sprintf('The records of "person" point at record %s of "region" (region), which the package owning "region" does not seed', $east), seeds: ['person' => 'email']);
    }

    public function testRefusesExportingAnObjectOfAnotherPackage(): void
    {
        $this->person();

        $this->assertRefused(['person', 'region'], 'Object "region" belongs to the installed package acme/places: export it with that one.');
    }

    public function testRefusesPointingAtAFieldAddedToARequiredObject(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createField('province', 'zone', 'relation', 'Zone', settings: ['target' => 'region']);
        $metadata->createObject('person', 'Person', 'People');
        $metadata->createField('person', 'region', 'relation', 'Region', settings: ['target' => 'region']);
        $metadata->createField('person', 'province', 'relation', 'Province', settings: ['target' => 'province', 'dependsOn' => 'region', 'dependsVia' => 'zone']);

        $this->assertRefused(['person'], 'Object "person" points at "province.zone" (province.dependsVia), which was added here to an object of acme/places');
    }

    public function testExportsAFormOverARequiredObject(): void
    {
        $this->person();
        self::getContainer()->get(MetadataManagerInterface::class)->saveForm('residents', "label: Residents\nobject: region\nsections:\n  - { name: people, label: People, object: person, via: region, mode: many }\n");

        $package = self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand('acme/people', '1.0.0', ['person'], ['residents'], license: 'MIT'));

        self::assertSame('^1.2', self::map($package->manifest->spec['requires'])['acme/places'] ?? null);
        $definition = self::map(self::document($package, 'forms/residents.yaml')->spec['definition']);
        self::assertSame('acme.region', $definition['object']);
        self::assertSame(['acme.person', 'acme.person.region'], [self::map(self::map($definition['sections'])[0])['object'] ?? null, self::map(self::map($definition['sections'])[0])['via'] ?? null]);
    }

    /**
     * A person, with a region and a province of acme/places, the province following the region.
     */
    private function person(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('person', 'Person', 'People');
        $metadata->createField('person', 'email', 'email', 'Email', true, true);
        $metadata->createField('person', 'region', 'relation', 'Region', settings: ['target' => 'region']);
        $metadata->createField('person', 'province', 'relation', 'Province', settings: ['target' => 'province', 'dependsOn' => 'region', 'dependsVia' => 'region']);
    }

    private function install(Package $package): void
    {
        self::getContainer()->get(PackageInstaller::class)->install($package, (string) $package->directory);
    }

    /**
     * @param list<string>               $objects
     * @param array<string, string|null> $seeds
     */
    private function export(array $objects, string $version = '1.0.0', array $seeds = []): Package
    {
        return self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand('acme/people', $version, $objects, license: 'MIT', seeds: $seeds));
    }

    /**
     * @param list<string>               $objects
     * @param non-empty-string           $message
     * @param array<string, string|null> $seeds
     */
    private function assertRefused(array $objects, string $message, array $seeds = []): void
    {
        try {
            $this->export($objects, seeds: $seeds);
            self::fail(\sprintf('Refused: %s', $message));
        } catch (InvalidExport $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function record(string $object, array $values): string
    {
        return self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput($object, $values))->id;
    }

    private function id(string $object, string $code): string
    {
        foreach (self::getContainer()->get(RecordFinderInterface::class)->latest($object) as $record) {
            if ($code === ($record->values['code'] ?? null)) {
                return $record->id;
            }
        }
        self::fail(\sprintf('No record of "%s" has the code "%s".', $object, $code));
    }

    private static function document(Package $package, string $path): Document
    {
        foreach ([$package->manifest, ...$package->documents] as $document) {
            if ($path === $document->path) {
                return $document;
            }
        }
        self::fail(\sprintf('No document %s.', $path));
    }

    /**
     * @return array<mixed>
     */
    private static function settings(Document $object, string $field): array
    {
        foreach (self::map($object->spec['fields'] ?? []) as $spec) {
            if (\is_array($spec) && $field === ($spec['name'] ?? null)) {
                return self::map($spec['settings'] ?? null);
            }
        }
        self::fail(\sprintf('No field "%s" in %s.', $field, $object->path));
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
