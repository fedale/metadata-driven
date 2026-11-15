<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Application\InstallReport;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Upgrade\Snapshot;
use App\Module\Package\Domain\Exception\InstallRefused;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\ExamplePackage;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\Package;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Installs a package requiring another one (acme/people on acme/places, in
 * tests/Fixtures/packages) against the real database: the requirement must be installed in a
 * version it allows, and the references resolve to its items by id.
 */
final class PackageRequiresTest extends KernelTestCase
{
    public function testRefusesAPackageWhoseRequirementIsNotInstalled(): void
    {
        $this->assertRefused(ExamplePackage::people(), 'package.yaml: spec.requires.acme/places: acme/places is required: it must be installed');

        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('person'));
    }

    public function testRefusesARequirementInstalledInAVersionItDoesNotAllow(): void
    {
        $this->install(self::withManifest(ExamplePackage::places(), static fn (array $spec): array => ['version' => '2.0.0'] + $spec));

        $this->assertRefused(ExamplePackage::people(), 'package.yaml: spec.requires.acme/places: acme/places 2.0.0 does not fit ^1.0.');
    }

    public function testInstallsOnTopOfThePackageItRequires(): void
    {
        $this->install(ExamplePackage::places());
        $report = $this->install(ExamplePackage::people());

        self::assertSame([['person'], 3, 1, 1], [$report->objects, $report->fields, $report->views, $report->records]);
        $person = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person');
        self::assertSame('region', $person->field('region')->relation()?->target);
        $province = $person->field('province')->relation();
        self::assertSame(['province', 'region', 'region'], [$province?->target, $province?->dependsOn, $province?->dependsVia]);

        // The seed points at the records of the required seeds, by their keys.
        $ada = self::byKey('person', 'email')['ada@example.com'];
        self::assertSame(self::byKey('region', 'code')['01']->id, $ada->values['region']);
        self::assertSame(self::byKey('province', 'code')['TO']->id, $ada->values['province']);
    }

    public function testRecordsTheReferencesToTheRequiredPackageByTheirIds(): void
    {
        $this->install(ExamplePackage::places());
        $this->install(ExamplePackage::people());

        // The snapshot is the next upgrade's base: a "local:region" there would read as a change.
        $snapshot = self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/people')?->getSnapshot() ?? [];
        self::assertSame(['acme/places' => '^1.0'], Snapshot::requires($snapshot));
        $person = Snapshot::toPackage($snapshot)->documentsOf(DocumentKind::Object)[0];
        self::assertSame(['target' => 'acme.region'], self::settings($person, 'region'));
        self::assertSame(['target' => 'acme.province', 'dependsOn' => 'acme.person.region', 'dependsVia' => 'acme.province.region'], self::settings($person, 'province'));
        // Read back now, the package's items are what it installed: nothing to upgrade.
        $owned = self::getContainer()->get(PackageExporter::class)->ownedState('acme/people');
        self::assertEquals(Snapshot::toPackage($snapshot)->documentsOf(DocumentKind::Object), $owned->documentsOf(DocumentKind::Object));
    }

    public function testFindsTheRequiredItemsByIdOnceTheUserRenamedThem(): void
    {
        $this->install(ExamplePackage::places());
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->renameObject('region', 'area');
        $metadata->renameField('area', 'code', 'istat');

        $this->install(ExamplePackage::people());

        $person = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person');
        self::assertSame('area', $person->field('region')->relation()?->target);
        self::assertSame('region', $person->field('province')->relation()?->dependsVia);
        self::assertSame(self::byKey('area', 'istat')['01']->id, self::byKey('person', 'email')['ada@example.com']->values['region']);
    }

    public function testRefusesARecordOfTheRequiredPackageTheUserDeleted(): void
    {
        $this->install(ExamplePackage::places());
        // The province first: it restricts nothing, and the region it points at stays.
        self::getContainer()->get(RecordManagerInterface::class)->delete('province', self::byKey('province', 'code')['TO']->id);

        $this->assertRefused(ExamplePackage::people(), 'seeds/person.yaml: spec.records.0.province: No record of "acme.province" has the key "TO".');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('person'));
    }

    public function testDoesNotTranslateTheItemsOfTheRequiredPackage(): void
    {
        $this->install(ExamplePackage::places());
        $people = ExamplePackage::people();
        $translations = new Document(DocumentKind::Translations, 'acme.translations.it', ['locale' => 'it', 'entries' => ['object.region.singular' => 'Zona']], 'translations/it.yaml');

        $this->assertRefused(new Package($people->manifest, [...$people->documents, $translations], $people->directory), 'translations/it.yaml: spec.entries.object.region.singular: Names no object or form of the package.');
    }

    public function testListsWhatEachPackageRequires(): void
    {
        $application = new Application(self::bootKernel());
        $install = new CommandTester($application->find('metadata:package:install'));
        $install->execute(['package' => ExamplePackage::PLACES]);
        $install->assertCommandIsSuccessful();
        $install->execute(['package' => ExamplePackage::PEOPLE]);
        $install->assertCommandIsSuccessful();

        $list = new CommandTester($application->find('metadata:package:list'));
        $list->execute([]);
        self::assertMatchesRegularExpression('~acme/people\s+1\.0\.0\s+acme/places \^1\.0~', $list->getDisplay());
    }

    public function testValidatesAgainstTheRequirementsAsInstalled(): void
    {
        $validate = new CommandTester((new Application(self::bootKernel()))->find('metadata:package:validate'));

        self::assertSame(1, $validate->execute(['directory' => ExamplePackage::PEOPLE]));
        self::assertStringContainsString('acme/places is required', $validate->getDisplay());

        $this->install(ExamplePackage::places());
        $validate->execute(['directory' => ExamplePackage::PEOPLE]);
        $validate->assertCommandIsSuccessful();
    }

    private function install(Package $package): InstallReport
    {
        return self::getContainer()->get(PackageInstaller::class)->install($package, (string) $package->directory);
    }

    private function assertRefused(Package $package, string $reason): void
    {
        try {
            $this->install($package);
            self::fail(\sprintf('Refused: %s', $reason));
        } catch (InstallRefused $e) {
            self::assertNotEmpty(array_filter($e->reasons, static fn (string $given): bool => str_starts_with($given, $reason)), implode("\n", $e->reasons));
        }
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private static function withManifest(Package $package, \Closure $change): Package
    {
        $manifest = $package->manifest;

        return new Package(new Document($manifest->kind, $manifest->id, $change($manifest->spec), $manifest->path), $package->documents, $package->directory);
    }

    /**
     * The settings of an object document's field, by its name.
     *
     * @return array<mixed>
     */
    private static function settings(Document $object, string $field): array
    {
        self::assertIsArray($object->spec['fields'] ?? null);
        foreach ($object->spec['fields'] as $spec) {
            if (\is_array($spec) && $field === ($spec['name'] ?? null)) {
                self::assertIsArray($spec['settings'] ?? null);

                return $spec['settings'];
            }
        }
        self::fail(\sprintf('No field "%s" in %s.', $field, $object->path));
    }

    /**
     * @return array<string, RecordOutput>
     */
    private static function byKey(string $object, string $key): array
    {
        $records = [];
        foreach (self::getContainer()->get(RecordFinderInterface::class)->latest($object) as $record) {
            $value = $record->values[$key] ?? null;
            self::assertIsString($value);
            $records[$value] = $record;
        }

        return $records;
    }
}
