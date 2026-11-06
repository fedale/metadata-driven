<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Template\Application\Command\ExportTemplateCommand;
use App\Module\Template\Application\TemplateExporter;
use App\Module\Template\Application\TemplateInstaller;
use App\Module\Template\Domain\Exception\InstallRefused;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\TemplateFormat\Document;
use App\TemplateFormat\DocumentKind;
use App\TemplateFormat\Package;
use App\Tests\Unit\TemplateFormat\PackageCheckerTest;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Installs the example template (tests/Unit/TemplateFormat/Fixtures/shop) through the real
 * metadata API, against the real database.
 */
final class TemplateInstallerTest extends KernelTestCase
{
    public function testInstallsEveryItemOfTheTemplate(): void
    {
        $report = $this->install(PackageCheckerTest::fixture());

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

    public function testExportingAnInstalledTemplateGivesItBack(): void
    {
        $template = PackageCheckerTest::fixture();
        $this->install($template);

        $exported = self::getContainer()->get(TemplateExporter::class)->export(new ExportTemplateCommand('acme/shop', '1.0.0', ['category', 'tag', 'product'], ['catalogue'], license: 'MIT'));

        // The ids recorded at install are the template's: the items keep them.
        self::assertEquals(self::byPath($template), self::byPath($exported));
    }

    public function testADryRunChangesNothing(): void
    {
        $report = $this->install(PackageCheckerTest::fixture(), dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(['category', 'product', 'tag'], $report->objects);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
        self::assertNull(self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop'));

        // Nothing it went through is left behind: the real install follows.
        self::assertFalse($this->install(PackageCheckerTest::fixture())->dryRun);
        self::assertTrue(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testAStepTheAppRefusesRollsEverythingBack(): void
    {
        // A layout placing a text nobody defined: the app refuses the view.
        $package = self::changed(PackageCheckerTest::fixture(), 'views/product.form.main.yaml', static fn (array $spec): array => ['template' => '<p>{t:missing}</p>'] + $spec);

        $this->assertRefused($package, 'views/product.form.main.yaml: spec: ');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('category'));
        self::assertNull(self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop'));
    }

    public function testRefusesWhatItCannotInstall(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('tag', 'Tag', 'Tags');
        $this->assertRefused(PackageCheckerTest::fixture(), 'objects/tag.yaml: spec.name: This instance already has an object "tag".');

        $requires = self::changed(PackageCheckerTest::fixture(), 'template.yaml', static fn (array $spec): array => ['requires' => ['format' => 'metadata-driven/v1', 'acme/geo-italy' => '^1.0']] + $spec);
        $this->assertRefused($requires, 'template.yaml: spec.requires.acme/geo-italy: A template requiring another one cannot be installed yet.');

        $invalid = self::changed(PackageCheckerTest::fixture(), 'objects/product.yaml', static fn (array $spec): array => ['plural' => ''] + $spec);
        $this->assertRefused($invalid, 'objects/product.yaml: spec.plural: Minimum string length is 1, found 0');
    }

    public function testTranslatesOnlyWhatTheTemplateBrings(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('account', 'Account', 'Accounts');
        $metadata->createField('account', 'email', 'email', 'Email');
        $metadata->translate('it', 'field.account.email', 'Posta elettronica');

        // A label of a local object: the template does not own it.
        $foreign = self::changed(PackageCheckerTest::fixture(), 'translations/it.yaml', static fn (array $spec): array => ['entries' => ['field.account.email' => 'Indirizzo']] + $spec);
        $this->assertRefused($foreign, 'translations/it.yaml: spec.entries.field.account.email: Names no object or form of the template.');

        // A text this instance already has: the template would rewrite it wherever it is placed.
        $metadata->translate('en', 'text.identity', 'Who we are');
        $this->assertRefused(PackageCheckerTest::fixture(), 'translations/en.yaml: spec.entries.text.identity: This instance already has the text "identity".');

        $italian = array_column(array_map(static fn ($entry): array => [$entry->key, $entry->translation], self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it')), 1, 0);
        self::assertSame('Posta elettronica', $italian['field.account.email']);
    }

    public function testATemplateIsInstalledOnce(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('placeholder', 'Placeholder', 'Placeholders');
        $this->install(PackageCheckerTest::fixture());

        $this->assertRefused(PackageCheckerTest::fixture(), 'acme/shop is already installed, in version 1.0.0.');
    }

    public function testLeavesOutTheLanguagesOfNoUse(): void
    {
        $package = PackageCheckerTest::fixture();
        $package = new Package($package->manifest, [...$package->documents, new Document(DocumentKind::Translations, 'acme.translations.fr', ['locale' => 'fr', 'entries' => ['field.product.name' => 'Nom']], 'translations/fr.yaml')]);

        self::assertSame(['fr'], $this->install($package)->skippedLocales);
    }

    public function testInstallsAndListsFromTheConsole(): void
    {
        $application = new Application(self::bootKernel());
        $install = new CommandTester($application->find('metadata:template:install'));

        $install->execute(['directory' => PackageCheckerTest::FIXTURE, '--dry-run' => true]);
        $install->assertCommandIsSuccessful();
        self::assertStringContainsString('Template acme/shop 1.0.0 can be installed. Dry run: nothing was changed.', $install->getDisplay());

        $install->execute(['directory' => PackageCheckerTest::FIXTURE]);
        $install->assertCommandIsSuccessful();
        self::assertStringContainsString('Template acme/shop 1.0.0 installed.', $install->getDisplay());

        $list = new CommandTester($application->find('metadata:template:list'));
        $list->execute([]);
        self::assertMatchesRegularExpression('~acme/shop\s+1\.0\.0~', $list->getDisplay());

        self::assertSame(1, $install->execute(['directory' => PackageCheckerTest::FIXTURE]));
        self::assertStringContainsString('acme/shop is already installed, in version 1.0.0.', $install->getDisplay());
    }

    private function install(Package $package, bool $dryRun = false): \App\Module\Template\Application\InstallReport
    {
        return self::getContainer()->get(TemplateInstaller::class)->install($package, PackageCheckerTest::FIXTURE, $dryRun);
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

        return new Package($manifest, $documents);
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
