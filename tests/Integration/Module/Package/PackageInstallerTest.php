<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Package\Application\Command\ExportPackageCommand;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Domain\Exception\InstallRefused;
use App\Module\Package\Domain\Exception\InvalidExport;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use App\Module\Record\Contract\Event\RecordWriting;
use App\Module\Record\Contract\Event\RecordWritten;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Tests\Support\ExamplePackage;
use App\Tests\Support\RemovesDirectories;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\PackageChecker;
use Fedale\PackageFormat\PackageDirectory;
use Fedale\PackageFormat\SeedRecords;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Installs the example package (tests/Fixtures/packages/shop) through the real
 * metadata API, against the real database.
 */
final class PackageInstallerTest extends KernelTestCase
{
    use RemovesDirectories;

    public function testInstallsEveryItemOfThePackage(): void
    {
        $report = $this->install(ExamplePackage::package());

        self::assertFalse($report->dryRun);
        self::assertSame(['category', 'product', 'tag'], $report->objects);
        self::assertSame([9, 2, ['catalogue'], 5], [$report->fields, $report->views, $report->forms, $report->translations]);

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        $product = $registry->getObject('product');
        self::assertSame('box', $product->icon);
        self::assertSame('name', $product->labelField);
        self::assertSame(['name', 'sku'], $product->searchFields);
        self::assertSame([['category', 'name']], $product->uniqueTogether);
        self::assertSame('category', $product->field('category')->relation()?->target);
        self::assertSame('restrict', $product->field('category')->relation()->onDelete->value);
        self::assertSame('tag', $product->field('tags')->manyToMany()?->target);
        $condition = $product->field('seats')->condition;
        self::assertNotNull($condition);
        self::assertSame(['kind', ['chair']], [$condition->field, $condition->values]);
        self::assertTrue($product->field('sku')->isUnique);
        self::assertSame(20, $product->field('sku')->settings['maxLength']);
        self::assertStringContainsString('{t:identity}', (string) $product->view(ViewKind::Form)?->source);
        self::assertSame('category', $registry->getForm('catalogue')->root->object);
        self::assertSame('category', $registry->getForm('catalogue')->root->sections[0]->via);

        $italian = array_column(array_map(static fn ($entry): array => [$entry->key, $entry->translation], self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it')), 1, 0);
        self::assertSame('Prodotto', $italian['object.product.singular']);
        self::assertSame('Identità', $italian['text.identity']);

        $installation = self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/shop');
        self::assertSame('1.0.0', $installation?->getVersion());
        self::assertArrayHasKey('objects/product.yaml', $installation->getSnapshot());
    }

    public function testWritesTheRecordsOfTheSeedsReferencesByKey(): void
    {
        self::assertSame(6, $this->install(ExamplePackage::package())->records);

        $finder = self::getContainer()->get(RecordFinderInterface::class);
        $byKey = static function (string $object, string $key) use ($finder): array {
            $records = [];
            foreach ($finder->latest($object) as $record) {
                $records[self::text($record->values[$key] ?? null)] = $record;
            }

            return $records;
        };
        $categories = $byKey('category', 'title');
        $tags = $byKey('tag', 'name');
        $products = $byKey('product', 'sku');
        self::assertEqualsCanonicalizing(['Chairs', 'Tables'], array_keys($categories));

        $chair = $products['OSL-01'];
        self::assertSame('Oslo chair', $chair->values['name']);
        self::assertSame($categories['Chairs']->id, $chair->values['category']);
        self::assertEqualsCanonicalizing([$tags['new']->id, $tags['wood']->id], $chair->values['tags']);
        self::assertSame(4, $chair->values['seats']);
        // The photo of the package, stored as an upload is: the content decides its type.
        $photo = self::getContainer()->get(FileStorageInterface::class)->find(self::text($chair->values['photo'] ?? null));
        self::assertSame(['oslo.png', 'image/png', 8, 6], [$photo?->originalName, $photo?->mimeType, $photo?->width, $photo?->height]);

        // An empty cell is no value: the table has no seats, and no photo.
        self::assertNull($products['BRG-02']->values['seats']);
        self::assertNull($products['BRG-02']->values['photo']);
    }

    public function testTheRecordsOfTheSeedsRaiseNoRecordEvent(): void
    {
        $raised = 0;
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $events->addListener(RecordWriting::class, static function () use (&$raised): void {
            ++$raised;
        });
        $events->addListener(RecordWritten::class, static function () use (&$raised): void {
            ++$raised;
        });

        self::assertSame(6, $this->install(ExamplePackage::package())->records);
        // A package's records are installed, not written by anyone: no workflow runs on them.
        self::assertSame(0, $raised);
    }

    public function testARecordMayPointAtOneOfItsSeedWrittenAfterIt(): void
    {
        $package = self::changed(ExamplePackage::package(), 'objects/category.yaml', static function (array $spec): array {
            $fields = \is_array($spec['fields'] ?? null) ? $spec['fields'] : [];
            $fields[] = ['id' => 'acme.category.parent', 'name' => 'parent', 'type' => 'relation', 'label' => 'Parent', 'settings' => ['target' => 'acme.category']];

            return ['fields' => $fields] + $spec;
        });
        // Chairs comes before its parent.
        $package = self::changed($package, 'seeds/category.yaml', static fn (array $spec): array => ['records' => [
            ['title' => 'Chairs', 'parent' => 'Furniture'],
            ['title' => 'Furniture'],
            ['title' => 'Tables', 'parent' => 'Furniture'],
        ]] + $spec);

        $this->install($package);

        $categories = [];
        foreach (self::getContainer()->get(RecordFinderInterface::class)->latest('category') as $record) {
            $categories[self::text($record->values['title'] ?? null)] = $record;
        }
        self::assertSame($categories['Furniture']->id, $categories['Chairs']->values['parent']);
        self::assertSame($categories['Furniture']->id, $categories['Tables']->values['parent']);
        self::assertNull($categories['Furniture']->values['parent']);
    }

    public function testARecordTheAppRefusesRollsTheInstallBack(): void
    {
        // A SKU that does not match the field's pattern.
        $package = self::changed(ExamplePackage::package(), 'seeds/product.yaml', static fn (array $spec): array => ['records' => [['sku' => 'oslo', 'name' => 'Oslo chair', 'category' => 'Chairs']]] + array_diff_key($spec, ['source' => true]));

        $this->assertRefused($package, 'seeds/product.yaml: spec.records.0: sku: ');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testExportingAnInstalledPackageGivesItBack(): void
    {
        $package = ExamplePackage::package();
        $this->install($package);

        $exported = self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand('acme/shop', '1.0.0', ['category', 'tag', 'product'], ['catalogue'], license: 'MIT'));

        // The ids recorded at install are the package's: the items keep them. The export does not
        // write seeds yet (the next step of phase 3): they are left out of the comparison.
        self::assertEquals(array_filter(self::byPath($package), static fn (Document $document): bool => DocumentKind::Seed !== $document->kind), self::byPath($exported));
    }

    public function testExportsTheRecordsAsSeedsThatReadBack(): void
    {
        $this->install(ExamplePackage::package());
        $directory = sys_get_temp_dir().'/md-seeds-'.bin2hex(random_bytes(4));

        try {
            $exported = self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand(
                'acme/shop', '1.1.0', ['category', 'tag', 'product'], license: 'MIT',
                seeds: ['category' => null, 'tag' => 'name', 'product' => 'sku'],
            ));
            self::getContainer()->get(PackageDirectory::class)->write($exported, $directory);
            $read = self::getContainer()->get(PackageDirectory::class)->read($directory);

            self::assertSame([], array_map(strval(...), self::getContainer()->get(PackageChecker::class)->check($read)));
            $seed = array_values(array_filter($read->documents, static fn (Document $document): bool => 'seeds/product.yaml' === $document->path))[0];
            self::assertSame(['object' => 'acme.product', 'key' => 'sku', 'source' => 'seeds/product.csv'], $seed->spec);

            $products = array_column(self::getContainer()->get(SeedRecords::class)->of($read, $seed), null, 'sku');
            $chair = $products['OSL-01'];
            // References by key, links separated by "|", the photo copied into the package.
            self::assertSame(['Oslo chair', 'chair', '4', 'Chairs'], [$chair['name'], $chair['kind'], $chair['seats'], $chair['category']]);
            self::assertEqualsCanonicalizing(['new', 'wood'], SeedRecords::list($chair['tags']));
            self::assertIsString($chair['photo']);
            self::assertMatchesRegularExpression('~^files/product/[0-9a-f-]{36}/oslo\.png$~', $chair['photo']);
            self::assertFileEquals(ExamplePackage::DIRECTORY.'/files/products/oslo.png', $directory.'/'.$chair['photo']);
            self::assertNull($products['BRG-02']['seats']);
        } finally {
            self::removeDirectory($directory);
        }
    }

    public function testExportsSeedsOnlyWithTheirReferences(): void
    {
        $this->install(ExamplePackage::package());
        $exporter = self::getContainer()->get(PackageExporter::class);

        foreach ([
            [['category' => null, 'product' => null], 'The records of "product" point at records of "tag" (tags): export its records too.'],
            [['category' => 'title', 'tag' => null, 'product' => 'name'], 'The records of "product" need a key, a unique field naming each record: "name" is not a unique field.'],
        ] as [$seeds, $message]) {
            try {
                $exporter->export(new ExportPackageCommand('acme/shop', '1.1.0', ['category', 'tag', 'product'], seeds: $seeds));
                self::fail($message);
            } catch (InvalidExport $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testADryRunChangesNothing(): void
    {
        $report = $this->install(ExamplePackage::package(), dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(['category', 'product', 'tag'], $report->objects);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
        self::assertNull(self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/shop'));

        // Nothing it went through is left behind: the real install follows.
        self::assertFalse($this->install(ExamplePackage::package())->dryRun);
        self::assertTrue(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testAStepTheAppRefusesRollsEverythingBack(): void
    {
        // A layout placing a text nobody defined: the app refuses the view.
        $package = self::changed(ExamplePackage::package(), 'views/product.form.main.yaml', static fn (array $spec): array => ['source' => '<p>{t:missing}</p>'] + $spec);

        $this->assertRefused($package, 'views/product.form.main.yaml: spec: ');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('category'));
        self::assertNull(self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/shop'));
    }

    public function testRefusesWhatItCannotInstall(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('tag', 'Tag', 'Tags');
        $this->assertRefused(ExamplePackage::package(), 'objects/tag.yaml: spec.name: This instance already has an object "tag".');

        $requires = self::changed(ExamplePackage::package(), 'package.yaml', static fn (array $spec): array => ['requires' => ['format' => 'metadata-driven/v2', 'acme/geo-italy' => '^1.0']] + $spec);
        $this->assertRefused($requires, 'package.yaml: spec.requires.acme/geo-italy: acme/geo-italy is required: it must be installed');

        $invalid = self::changed(ExamplePackage::package(), 'objects/product.yaml', static fn (array $spec): array => ['plural' => ''] + $spec);
        $this->assertRefused($invalid, 'objects/product.yaml: spec.plural: Minimum string length is 1, found 0');
    }

    public function testTranslatesOnlyWhatThePackageBrings(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('account', 'Account', 'Accounts');
        $metadata->createField('account', 'email', 'email', 'Email');
        $metadata->translate('it', 'field.account.email', 'Posta elettronica');

        // A label of a local object: the package does not own it.
        $foreign = self::changed(ExamplePackage::package(), 'translations/it.yaml', static fn (array $spec): array => ['entries' => ['field.account.email' => 'Indirizzo']] + $spec);
        $this->assertRefused($foreign, 'translations/it.yaml: spec.entries.field.account.email: Names no object or form of the package.');

        // A text this instance already has: the package would rewrite it wherever it is placed.
        $metadata->translate('en', 'text.identity', 'Who we are');
        $this->assertRefused(ExamplePackage::package(), 'translations/en.yaml: spec.entries.text.identity: This instance already has the text "identity".');

        $italian = array_column(array_map(static fn ($entry): array => [$entry->key, $entry->translation], self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it')), 1, 0);
        self::assertSame('Posta elettronica', $italian['field.account.email']);
    }

    public function testAPackageIsInstalledOnce(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('placeholder', 'Placeholder', 'Placeholders');
        $this->install(ExamplePackage::package());

        $this->assertRefused(ExamplePackage::package(), 'acme/shop is already installed, in version 1.0.0.');
    }

    public function testLeavesOutTheLanguagesOfNoUse(): void
    {
        $package = ExamplePackage::package();
        $package = new Package($package->manifest, [...$package->documents, new Document(DocumentKind::Translations, 'acme.translations.fr', ['locale' => 'fr', 'entries' => ['field.product.name' => 'Nom']], 'translations/fr.yaml')], $package->directory);

        self::assertSame(['fr'], $this->install($package)->skippedLocales);
    }

    public function testInstallsAndListsFromTheConsole(): void
    {
        $application = new Application(self::bootKernel());
        $install = new CommandTester($application->find('metadata:package:install'));

        $install->execute(['package' => ExamplePackage::DIRECTORY, '--dry-run' => true]);
        $install->assertCommandIsSuccessful();
        self::assertStringContainsString('Package acme/shop 1.0.0 can be installed. Dry run: nothing was changed.', $install->getDisplay());

        $install->execute(['package' => ExamplePackage::DIRECTORY]);
        $install->assertCommandIsSuccessful();
        self::assertStringContainsString('Package acme/shop 1.0.0 installed.', $install->getDisplay());

        $list = new CommandTester($application->find('metadata:package:list'));
        $list->execute([]);
        self::assertMatchesRegularExpression('~acme/shop\s+1\.0\.0~', $list->getDisplay());

        self::assertSame(1, $install->execute(['package' => ExamplePackage::DIRECTORY]));
        self::assertStringContainsString('acme/shop is already installed, in version 1.0.0.', $install->getDisplay());
    }

    private function install(Package $package, bool $dryRun = false): \App\Module\Package\Application\InstallReport
    {
        return self::getContainer()->get(PackageInstaller::class)->install($package, ExamplePackage::DIRECTORY, $dryRun);
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
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
    private static function changed(Package $package, string $path, \Closure $change): Package
    {
        $documents = array_map(
            static fn (Document $document): Document => $document->path === $path ? new Document($document->kind, $document->id, $change($document->spec), $path) : $document,
            $package->documents,
        );
        $manifest = $package->manifest->path === $path ? new Document($package->manifest->kind, $package->manifest->id, $change($package->manifest->spec), $path) : $package->manifest;

        return new Package($manifest, $documents, $package->directory);
    }

    /**
     * The documents but the manifest (its label and description are the exporter's), by path.
     *
     * @return array<string, Document>
     */
    private static function byPath(Package $package): array
    {
        $documents = [];
        foreach ($package->documents as $document) {
            $documents[$document->path] = $document;
        }
        ksort($documents);

        return $documents;
    }
}
