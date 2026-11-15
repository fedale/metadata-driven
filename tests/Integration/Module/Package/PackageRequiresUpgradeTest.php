<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Upgrade\PackageUpgrader;
use App\Module\Package\Application\Upgrade\Snapshot;
use App\Module\Package\Application\Upgrade\UpgradeReport;
use App\Module\Package\Domain\Exception\UpgradeRefused;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Tests\Support\ExamplePackage;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\Package;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Upgrades packages requiring packages (acme/people on acme/places ^1.0, in
 * tests/Fixtures/packages) against the real database: a requiring package keeps its
 * requirements as installed, a required one may not break the packages requiring it.
 */
final class PackageRequiresUpgradeTest extends KernelTestCase
{
    public function testUpgradesAPackageRequiringAnother(): void
    {
        $this->install(ExamplePackage::places());
        $this->install(self::people('1.0.0', managed: true));

        $report = $this->upgrade(self::people('1.1.0', managed: true, birthRegion: true, bob: true));

        self::assertSame(['1.0.0', '1.1.0'], [$report->from, $report->to]);
        $person = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person');
        self::assertSame('region', $person->field('birth_region')->relation()?->target);
        // The new record points at the required seeds' records, by their keys.
        $bob = self::byKey('person', 'email')['bob@example.com'];
        self::assertSame(self::byKey('region', 'code')['02']->id, $bob->values['region']);
        self::assertSame(self::byKey('province', 'code')['NA']->id, $bob->values['province']);

        // The snapshot names the required items by id, and reads as what the instance holds.
        $snapshot = self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/people')?->getSnapshot() ?? [];
        self::assertSame(['acme/places' => '^1.0'], Snapshot::requires($snapshot));
        self::assertSame(['target' => 'acme.region'], self::settings(Snapshot::toPackage($snapshot)->documentsOf(DocumentKind::Object)[0], 'birth_region'));
        $owned = self::getContainer()->get(PackageExporter::class)->ownedState('acme/people');
        self::assertEquals(Snapshot::toPackage($snapshot)->documentsOf(DocumentKind::Object), $owned->documentsOf(DocumentKind::Object));
    }

    public function testFollowsARequiredObjectTheUserRenamed(): void
    {
        $this->install(ExamplePackage::places());
        $this->install(self::people('1.0.0'));
        self::getContainer()->get(MetadataManagerInterface::class)->renameObject('region', 'area');

        $this->upgrade(self::people('1.1.0', birthRegion: true));

        self::assertSame('area', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person')->field('birth_region')->relation()?->target);
    }

    public function testRefusesAnUpgradeItsRequirementAsInstalledDoesNotFit(): void
    {
        $this->install(ExamplePackage::places());
        $this->install(self::people('1.0.0'));

        $this->assertRefused(self::people('1.1.0', birthRegion: true, requires: '^1.3'), 'package.yaml: spec.requires.acme/places: acme/places 1.2.0 does not fit ^1.3.');

        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person')->hasField('birth_region'));
        self::assertSame('1.0.0', self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/people')?->getVersion());
    }

    public function testUpgradesARequiredPackageThePackagesRequiringItStillFit(): void
    {
        $this->install(ExamplePackage::places());
        $this->install(self::people('1.0.0'));

        $this->upgrade(self::places('1.3.0', static function (array $spec): array {
            $spec['fields'] = [...self::list($spec['fields'] ?? []), ['id' => 'acme.region.capital', 'name' => 'capital', 'type' => 'text', 'label' => 'Capital']];

            return $spec;
        }));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        self::assertTrue($registry->getObject('region')->hasField('capital'));
        self::assertSame('region', $registry->getObject('person')->field('region')->relation()?->target);
        // The requiring package upgrades on top of it in turn.
        $this->upgrade(self::people('1.1.0', birthRegion: true));
        self::assertSame('region', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person')->field('birth_region')->relation()?->target);
    }

    public function testRefusesARequiredVersionThatWouldBreakAPackageRequiringIt(): void
    {
        $this->install(ExamplePackage::places());
        $this->install(self::people('1.0.0'));
        // 2.0.0 drops the province's region, which the person's province names (dependsVia).
        $places = self::places('2.0.0', province: static function (array $spec): array {
            $spec['fields'] = array_values(array_filter(self::list($spec['fields'] ?? []), static fn (mixed $field): bool => 'region' !== (self::map($field)['name'] ?? null)));

            return $spec;
        });

        $reasons = $this->assertRefused($places, 'acme/people 1.0.0, which requires acme/places: package.yaml: spec.requires.acme/places: acme/places 2.0.0 does not fit ^1.0.');
        self::assertNotEmpty(array_filter($reasons, static fn (string $reason): bool => str_starts_with($reason, 'acme/people 1.0.0, which requires acme/places: objects/person.yaml') && str_contains($reason, 'acme.province.region')), implode("\n", $reasons));
        self::assertTrue(self::getContainer()->get(MetadataRegistryInterface::class)->getObject('province')->hasField('region'));
        // The admin's preview lists the same refusals.
        self::assertSame($reasons, self::getContainer()->get(PackageUpgrader::class)->preview($places)->refusals);
    }

    private function install(Package $package): void
    {
        self::getContainer()->get(PackageInstaller::class)->install($package, (string) $package->directory);
    }

    private function upgrade(Package $package): UpgradeReport
    {
        return self::getContainer()->get(PackageUpgrader::class)->upgrade($package, (string) $package->directory);
    }

    /**
     * @return list<string> every reason given
     */
    private function assertRefused(Package $package, string $reason): array
    {
        try {
            $this->upgrade($package);
            self::fail(\sprintf('Refused: %s', $reason));
        } catch (UpgradeRefused $e) {
            self::assertContains($reason, $e->reasons, implode("\n", $e->reasons));

            return $e->reasons;
        }
    }

    /**
     * acme/people in a version: its seed managed or not, with a birth region (a new relation to
     * the required region), with a second person, requiring another version of acme/places.
     */
    private static function people(string $version, bool $managed = false, bool $birthRegion = false, bool $bob = false, string $requires = '^1.0'): Package
    {
        $people = ExamplePackage::people();
        $documents = [];
        foreach ($people->documents as $document) {
            $spec = $document->spec;
            if ('objects/person.yaml' === $document->path && $birthRegion) {
                $spec['fields'] = [...self::list($spec['fields'] ?? []), ['id' => 'acme.person.birth_region', 'name' => 'birth_region', 'type' => 'relation', 'label' => 'Birth region', 'settings' => ['target' => 'acme.region']]];
            }
            if (DocumentKind::Seed === $document->kind) {
                $spec['mode'] = $managed ? 'managed' : 'once';
                if ($bob) {
                    $spec['records'] = [...self::list($spec['records'] ?? []), ['email' => 'bob@example.com', 'region' => '02', 'province' => 'NA']];
                }
            }
            $documents[] = new Document($document->kind, $document->id, $spec, $document->path);
        }
        $manifest = $people->manifest;
        $spec = ['version' => $version, 'requires' => ['format' => 'metadata-driven/v2', 'acme/places' => $requires]] + $manifest->spec;

        return new Package(new Document($manifest->kind, $manifest->id, $spec, $manifest->path), $documents, $people->directory);
    }

    /**
     * acme/places in a version, its region or province object changed; a province without its
     * region seeded without one too.
     *
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $region
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $province
     */
    private static function places(string $version, ?\Closure $region = null, ?\Closure $province = null): Package
    {
        $places = ExamplePackage::places();
        $documents = [];
        foreach ($places->documents as $document) {
            $spec = match ($document->path) {
                'objects/region.yaml' => null === $region ? $document->spec : $region($document->spec),
                'objects/province.yaml' => null === $province ? $document->spec : $province($document->spec),
                default => $document->spec,
            };
            if (null !== $province && 'acme.seed.province' === $document->id) {
                $spec['records'] = array_map(static fn (mixed $row): array => array_diff_key(self::map($row), ['region' => true]), self::list($spec['records'] ?? []));
            }
            $documents[] = new Document($document->kind, $document->id, $spec, $document->path);
        }
        $manifest = $places->manifest;

        return new Package(new Document($manifest->kind, $manifest->id, ['version' => $version] + $manifest->spec, $manifest->path), $documents, $places->directory);
    }

    /**
     * The settings of an object document's field, by its name.
     *
     * @return array<mixed>
     */
    private static function settings(Document $object, string $field): array
    {
        foreach (self::list($object->spec['fields'] ?? []) as $spec) {
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

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
