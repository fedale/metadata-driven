<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Template\Application\InstallReport;
use App\Module\Template\Application\TemplateExporter;
use App\Module\Template\Application\TemplateInstaller;
use App\Module\Template\Application\Upgrade\Snapshot;
use App\Module\Template\Domain\Exception\InstallRefused;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Tests\Support\ExampleTemplate;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Installs a template requiring another one (acme/people on acme/places, in
 * tests/Fixtures/templates) against the real database: the requirement must be installed in a
 * version it allows, and the references resolve to its items by id.
 */
final class TemplateRequiresTest extends KernelTestCase
{
    public function testRefusesATemplateWhoseRequirementIsNotInstalled(): void
    {
        $this->assertRefused(ExampleTemplate::people(), 'template.yaml: spec.requires.acme/places: acme/places is required: it must be installed');

        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('person'));
    }

    public function testRefusesARequirementInstalledInAVersionItDoesNotAllow(): void
    {
        $this->install(self::withManifest(ExampleTemplate::places(), static fn (array $spec): array => ['version' => '2.0.0'] + $spec));

        $this->assertRefused(ExampleTemplate::people(), 'template.yaml: spec.requires.acme/places: acme/places 2.0.0 does not fit ^1.0.');
    }

    public function testInstallsOnTopOfTheTemplateItRequires(): void
    {
        $this->install(ExampleTemplate::places());
        $report = $this->install(ExampleTemplate::people());

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

    public function testRecordsTheReferencesToTheRequiredTemplateByTheirIds(): void
    {
        $this->install(ExampleTemplate::places());
        $this->install(ExampleTemplate::people());

        // The snapshot is the next upgrade's base: a "local:region" there would read as a change.
        $snapshot = self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/people')?->getSnapshot() ?? [];
        self::assertSame(['acme/places' => '^1.0'], Snapshot::requires($snapshot));
        $person = Snapshot::toPackage($snapshot)->documentsOf(DocumentKind::Object)[0];
        self::assertSame(['target' => 'acme.region'], self::settings($person, 'region'));
        self::assertSame(['target' => 'acme.province', 'dependsOn' => 'acme.person.region', 'dependsVia' => 'acme.province.region'], self::settings($person, 'province'));
        // Read back now, the template's items are what it installed: nothing to upgrade.
        $owned = self::getContainer()->get(TemplateExporter::class)->ownedState('acme/people');
        self::assertEquals(Snapshot::toPackage($snapshot)->documentsOf(DocumentKind::Object), $owned->documentsOf(DocumentKind::Object));
    }

    public function testFindsTheRequiredItemsByIdOnceTheUserRenamedThem(): void
    {
        $this->install(ExampleTemplate::places());
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->renameObject('region', 'area');
        $metadata->renameField('area', 'code', 'istat');

        $this->install(ExampleTemplate::people());

        $person = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person');
        self::assertSame('area', $person->field('region')->relation()?->target);
        self::assertSame('region', $person->field('province')->relation()?->dependsVia);
        self::assertSame(self::byKey('area', 'istat')['01']->id, self::byKey('person', 'email')['ada@example.com']->values['region']);
    }

    public function testRefusesARecordOfTheRequiredTemplateTheUserDeleted(): void
    {
        $this->install(ExampleTemplate::places());
        // The province first: it restricts nothing, and the region it points at stays.
        self::getContainer()->get(RecordManagerInterface::class)->delete('province', self::byKey('province', 'code')['TO']->id);

        $this->assertRefused(ExampleTemplate::people(), 'seeds/person.yaml: spec.records.0.province: No record of "acme.province" has the key "TO".');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('person'));
    }

    public function testDoesNotTranslateTheItemsOfTheRequiredTemplate(): void
    {
        $this->install(ExampleTemplate::places());
        $people = ExampleTemplate::people();
        $translations = new Document(DocumentKind::Translations, 'acme.translations.it', ['locale' => 'it', 'entries' => ['object.region.singular' => 'Zona']], 'translations/it.yaml');

        $this->assertRefused(new Package($people->manifest, [...$people->documents, $translations], $people->directory), 'translations/it.yaml: spec.entries.object.region.singular: Names no object or form of the template.');
    }

    public function testListsWhatEachTemplateRequires(): void
    {
        $application = new Application(self::bootKernel());
        $install = new CommandTester($application->find('metadata:template:install'));
        $install->execute(['template' => ExampleTemplate::PLACES]);
        $install->assertCommandIsSuccessful();
        $install->execute(['template' => ExampleTemplate::PEOPLE]);
        $install->assertCommandIsSuccessful();

        $list = new CommandTester($application->find('metadata:template:list'));
        $list->execute([]);
        self::assertMatchesRegularExpression('~acme/people\s+1\.0\.0\s+acme/places \^1\.0~', $list->getDisplay());
    }

    public function testValidatesAgainstTheRequirementsAsInstalled(): void
    {
        $validate = new CommandTester((new Application(self::bootKernel()))->find('metadata:template:validate'));

        self::assertSame(1, $validate->execute(['directory' => ExampleTemplate::PEOPLE]));
        self::assertStringContainsString('acme/places is required', $validate->getDisplay());

        $this->install(ExampleTemplate::places());
        $validate->execute(['directory' => ExampleTemplate::PEOPLE]);
        $validate->assertCommandIsSuccessful();
    }

    private function install(Package $package): InstallReport
    {
        return self::getContainer()->get(TemplateInstaller::class)->install($package, (string) $package->directory);
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
