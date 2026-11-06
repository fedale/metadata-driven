<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Template\Application\Command\ExportTemplateCommand;
use App\Module\Template\Application\TemplateExporter;
use App\Module\Template\Application\TemplateInstaller;
use App\Module\Template\Domain\Exception\InstallRefused;
use App\Module\Template\Domain\Exception\InvalidExport;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Tests\Support\ExampleTemplate;
use App\Tests\Support\RemovesDirectories;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\SeedRecords;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Installs the example template (tests/Fixtures/templates/shop) through the real
 * metadata API, against the real database.
 */
final class TemplateInstallerTest extends KernelTestCase
{
    use RemovesDirectories;

    public function testInstallsEveryItemOfTheTemplate(): void
    {
        $report = $this->install(ExampleTemplate::package());

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
        self::assertStringContainsString('{t:identity}', (string) $product->view(ViewKind::Form)?->template);
        self::assertSame('category', $registry->getForm('catalogue')->root->object);
        self::assertSame('category', $registry->getForm('catalogue')->root->sections[0]->via);

        $italian = array_column(array_map(static fn ($entry): array => [$entry->key, $entry->translation], self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it')), 1, 0);
        self::assertSame('Prodotto', $italian['object.product.singular']);
        self::assertSame('Identità', $italian['text.identity']);

        $installation = self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop');
        self::assertSame('1.0.0', $installation?->getVersion());
        self::assertArrayHasKey('objects/product.yaml', $installation->getSnapshot());
    }

    public function testWritesTheRecordsOfTheSeedsReferencesByKey(): void
    {
        self::assertSame(6, $this->install(ExampleTemplate::package())->records);

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

    public function testARecordMayPointAtOneOfItsSeedWrittenAfterIt(): void
    {
        $package = self::changed(ExampleTemplate::package(), 'objects/category.yaml', static function (array $spec): array {
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
        $package = self::changed(ExampleTemplate::package(), 'seeds/product.yaml', static fn (array $spec): array => ['records' => [['sku' => 'oslo', 'name' => 'Oslo chair', 'category' => 'Chairs']]] + array_diff_key($spec, ['source' => true]));

        $this->assertRefused($package, 'seeds/product.yaml: spec.records.0: sku: ');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testExportingAnInstalledTemplateGivesItBack(): void
    {
        $template = ExampleTemplate::package();
        $this->install($template);

        $exported = self::getContainer()->get(TemplateExporter::class)->export(new ExportTemplateCommand('acme/shop', '1.0.0', ['category', 'tag', 'product'], ['catalogue'], license: 'MIT'));

        // The ids recorded at install are the template's: the items keep them. The export does not
        // write seeds yet (the next step of phase 3): they are left out of the comparison.
        self::assertEquals(array_filter(self::byPath($template), static fn (Document $document): bool => DocumentKind::Seed !== $document->kind), self::byPath($exported));
    }

    public function testExportsTheRecordsAsSeedsThatReadBack(): void
    {
        $this->install(ExampleTemplate::package());
        $directory = sys_get_temp_dir().'/md-seeds-'.bin2hex(random_bytes(4));

        try {
            $exported = self::getContainer()->get(TemplateExporter::class)->export(new ExportTemplateCommand(
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
            // References by key, links separated by "|", the photo copied into the template.
            self::assertSame(['Oslo chair', 'chair', '4', 'Chairs'], [$chair['name'], $chair['kind'], $chair['seats'], $chair['category']]);
            self::assertEqualsCanonicalizing(['new', 'wood'], SeedRecords::list($chair['tags']));
            self::assertIsString($chair['photo']);
            self::assertMatchesRegularExpression('~^files/product/[0-9a-f-]{36}/oslo\.png$~', $chair['photo']);
            self::assertFileEquals(ExampleTemplate::DIRECTORY.'/files/products/oslo.png', $directory.'/'.$chair['photo']);
            self::assertNull($products['BRG-02']['seats']);
        } finally {
            self::removeDirectory($directory);
        }
    }

    public function testExportsSeedsOnlyWithTheirReferences(): void
    {
        $this->install(ExampleTemplate::package());
        $exporter = self::getContainer()->get(TemplateExporter::class);

        foreach ([
            [['category' => null, 'product' => null], 'The records of "product" point at records of "tag" (tags): export its records too.'],
            [['category' => 'title', 'tag' => null, 'product' => 'name'], 'The records of "product" need a key, a unique field naming each record: "name" is not a unique field.'],
        ] as [$seeds, $message]) {
            try {
                $exporter->export(new ExportTemplateCommand('acme/shop', '1.1.0', ['category', 'tag', 'product'], seeds: $seeds));
                self::fail($message);
            } catch (InvalidExport $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testADryRunChangesNothing(): void
    {
        $report = $this->install(ExampleTemplate::package(), dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(['category', 'product', 'tag'], $report->objects);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
        self::assertNull(self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop'));

        // Nothing it went through is left behind: the real install follows.
        self::assertFalse($this->install(ExampleTemplate::package())->dryRun);
        self::assertTrue(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testAStepTheAppRefusesRollsEverythingBack(): void
    {
        // A layout placing a text nobody defined: the app refuses the view.
        $package = self::changed(ExampleTemplate::package(), 'views/product.form.main.yaml', static fn (array $spec): array => ['template' => '<p>{t:missing}</p>'] + $spec);

        $this->assertRefused($package, 'views/product.form.main.yaml: spec: ');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('category'));
        self::assertNull(self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop'));
    }

    public function testRefusesWhatItCannotInstall(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('tag', 'Tag', 'Tags');
        $this->assertRefused(ExampleTemplate::package(), 'objects/tag.yaml: spec.name: This instance already has an object "tag".');

        $requires = self::changed(ExampleTemplate::package(), 'template.yaml', static fn (array $spec): array => ['requires' => ['format' => 'metadata-driven/v1', 'acme/geo-italy' => '^1.0']] + $spec);
        $this->assertRefused($requires, 'template.yaml: spec.requires.acme/geo-italy: A template requiring another one cannot be installed yet.');

        $invalid = self::changed(ExampleTemplate::package(), 'objects/product.yaml', static fn (array $spec): array => ['plural' => ''] + $spec);
        $this->assertRefused($invalid, 'objects/product.yaml: spec.plural: Minimum string length is 1, found 0');
    }

    public function testTranslatesOnlyWhatTheTemplateBrings(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('account', 'Account', 'Accounts');
        $metadata->createField('account', 'email', 'email', 'Email');
        $metadata->translate('it', 'field.account.email', 'Posta elettronica');

        // A label of a local object: the template does not own it.
        $foreign = self::changed(ExampleTemplate::package(), 'translations/it.yaml', static fn (array $spec): array => ['entries' => ['field.account.email' => 'Indirizzo']] + $spec);
        $this->assertRefused($foreign, 'translations/it.yaml: spec.entries.field.account.email: Names no object or form of the template.');

        // A text this instance already has: the template would rewrite it wherever it is placed.
        $metadata->translate('en', 'text.identity', 'Who we are');
        $this->assertRefused(ExampleTemplate::package(), 'translations/en.yaml: spec.entries.text.identity: This instance already has the text "identity".');

        $italian = array_column(array_map(static fn ($entry): array => [$entry->key, $entry->translation], self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it')), 1, 0);
        self::assertSame('Posta elettronica', $italian['field.account.email']);
    }

    public function testATemplateIsInstalledOnce(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('placeholder', 'Placeholder', 'Placeholders');
        $this->install(ExampleTemplate::package());

        $this->assertRefused(ExampleTemplate::package(), 'acme/shop is already installed, in version 1.0.0.');
    }

    public function testLeavesOutTheLanguagesOfNoUse(): void
    {
        $package = ExampleTemplate::package();
        $package = new Package($package->manifest, [...$package->documents, new Document(DocumentKind::Translations, 'acme.translations.fr', ['locale' => 'fr', 'entries' => ['field.product.name' => 'Nom']], 'translations/fr.yaml')], $package->directory);

        self::assertSame(['fr'], $this->install($package)->skippedLocales);
    }

    public function testInstallsAndListsFromTheConsole(): void
    {
        $application = new Application(self::bootKernel());
        $install = new CommandTester($application->find('metadata:template:install'));

        $install->execute(['template' => ExampleTemplate::DIRECTORY, '--dry-run' => true]);
        $install->assertCommandIsSuccessful();
        self::assertStringContainsString('Template acme/shop 1.0.0 can be installed. Dry run: nothing was changed.', $install->getDisplay());

        $install->execute(['template' => ExampleTemplate::DIRECTORY]);
        $install->assertCommandIsSuccessful();
        self::assertStringContainsString('Template acme/shop 1.0.0 installed.', $install->getDisplay());

        $list = new CommandTester($application->find('metadata:template:list'));
        $list->execute([]);
        self::assertMatchesRegularExpression('~acme/shop\s+1\.0\.0~', $list->getDisplay());

        self::assertSame(1, $install->execute(['template' => ExampleTemplate::DIRECTORY]));
        self::assertStringContainsString('acme/shop is already installed, in version 1.0.0.', $install->getDisplay());
    }

    private function install(Package $package, bool $dryRun = false): \App\Module\Template\Application\InstallReport
    {
        return self::getContainer()->get(TemplateInstaller::class)->install($package, ExampleTemplate::DIRECTORY, $dryRun);
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
