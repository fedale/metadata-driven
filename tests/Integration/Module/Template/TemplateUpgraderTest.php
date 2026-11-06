<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Template\Application\TemplateInstaller;
use App\Module\Template\Application\Upgrade\Strategy;
use App\Module\Template\Application\Upgrade\TemplateUpgrader;
use App\Module\Template\Application\Upgrade\UpgradeReport;
use App\Module\Template\Domain\Exception\UpgradeRefused;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Tests\Support\ExampleTemplate;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Upgrades the example template (tests/Fixtures/templates/shop) from 1.0.0 to a 1.1.0
 * built here, against the real database.
 */
final class TemplateUpgraderTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::getContainer()->get(TemplateInstaller::class)->install(ExampleTemplate::package(), ExampleTemplate::DIRECTORY);
    }

    public function testAppliesWhatTheNewVersionChanged(): void
    {
        $report = $this->upgrade($this->newVersion([
            'objects/product.yaml' => static function (array $spec): array {
                $spec['label'] = 'Item';
                $spec['fields'] = self::withField($spec['fields'], 'acme.product.sku', ['name' => 'code']);
                $spec['fields'][] = ['id' => 'acme.product.weight', 'name' => 'weight', 'type' => 'number', 'label' => 'Weight', 'settings' => ['decimals' => 2]];

                return $spec;
            },
            'views/product.form.main.yaml' => static fn (array $spec): array => ['template' => "<fieldset><legend>{t:identity}</legend>{name} {code}</fieldset>\n"] + $spec,
            'views/product.grid.main.yaml' => null,
            'translations/it.yaml' => static fn (array $spec): array => ['entries' => ['field.product.weight' => 'Peso'] + self::map($spec['entries'])] + $spec,
        ], '2.0.0'));

        self::assertSame(['1.0.0', '2.0.0', false], [$report->from, $report->to, $report->dryRun]);
        $product = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('product');
        self::assertSame('Item', $product->labelSingular);
        self::assertFalse($product->hasField('sku'));
        self::assertSame(2, $product->field('weight')->settings['decimals']);
        // Renamed, not replaced: the column and its values followed.
        $codes = array_map(static fn ($record): mixed => $record->values['code'], iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('product'), false));
        self::assertEqualsCanonicalizing(['OSL-01', 'BRG-02'], $codes);
        self::assertStringContainsString('{code}', (string) $product->view(ViewKind::Form)?->template);
        self::assertNull($product->view(ViewKind::Grid));
        self::assertSame('Peso', $this->italian()['field.product.weight']);
        self::assertSame('2.0.0', self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop')?->getVersion());
    }

    public function testKeepsWhatOnlyTheInstanceChanged(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->relabelObject('product', 'Article', 'Articles');
        $metadata->createField('product', 'notes', 'text', 'Notes');

        $this->upgrade($this->newVersion(['objects/category.yaml' => static fn (array $spec): array => ['label' => 'Family'] + $spec]));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        self::assertSame('Family', $registry->getObject('category')->labelSingular);
        self::assertSame('Article', $registry->getObject('product')->labelSingular);
        // A field the template does not own is never touched.
        self::assertTrue($registry->getObject('product')->hasField('notes'));
    }

    public function testStopsAtAConflictUnlessAStrategySaysWhoWins(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->relabelObject('product', 'Article', 'Articles');
        $newVersion = $this->newVersion(['objects/product.yaml' => static fn (array $spec): array => ['label' => 'Item'] + $spec]);

        try {
            $this->upgrade($newVersion);
            self::fail('A conflict must stop the upgrade.');
        } catch (UpgradeRefused $e) {
            self::assertTrue($e->conflicts);
            self::assertSame(['objects/product.yaml: change object acme.product, changed here too'], $e->reasons);
        }

        self::assertTrue($this->upgrade($newVersion, Strategy::Overwrite, dryRun: true)->dryRun);
        self::assertSame('Article', $this->label('product'));
        self::assertSame(['objects/product.yaml: change object acme.product'], $this->upgrade($newVersion, Strategy::Keep)->kept);
        self::assertSame('Article', $this->label('product'));
    }

    public function testTheNewVersionWinsWhenAskedTo(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->relabelObject('product', 'Article', 'Articles');

        $this->upgrade($this->newVersion(['objects/product.yaml' => static fn (array $spec): array => ['label' => 'Item'] + $spec]), Strategy::Overwrite);

        self::assertSame('Item', $this->label('product'));
    }

    public function testArchivesWhatTheNewVersionRemoved(): void
    {
        $this->upgrade($this->newVersion([
            'objects/product.yaml' => static fn (array $spec): array => ['fields' => array_values(array_filter(self::map($spec['fields']), static fn (mixed $field): bool => \is_array($field) && 'acme.product.seats' !== $field['id']))] + $spec,
            'forms/catalogue.yaml' => null,
        ], '2.0.0'));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        self::assertFalse($registry->getObject('product')->hasField('seats'));
        self::assertArrayHasKey('seats', $registry->getObject('product')->archivedFields);
        self::assertFalse($registry->hasForm('catalogue'));
    }

    public function testRefusesWhatItCannotUpgrade(): void
    {
        $this->assertRefused(
            $this->newVersion(['objects/product.yaml' => static fn (array $spec): array => ['fields' => self::withField($spec['fields'], 'acme.product.seats', ['type' => 'text', 'settings' => []])] + $spec]),
            'objects/product.yaml: field acme.product.seats: its type changed from number to text, which an upgrade cannot do yet: change it by hand first.',
        );
        $this->assertRefused($this->newVersion([], '1.0.0'), 'acme/shop 1.0.0 is installed: 1.0.0 is not newer.');
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('tag_group', 'Tag group', 'Tag groups');
        $this->assertRefused(
            $this->newVersion(['objects/tag.yaml' => static fn (array $spec): array => ['name' => 'tag_group'] + $spec], '2.0.0'),
            'objects/tag.yaml: object acme.tag: this instance already has an object "tag_group".',
        );
    }

    public function testTheVersionNumberMustFitTheChanges(): void
    {
        $this->assertRefused(
            $this->newVersion(['forms/catalogue.yaml' => null], '1.1.0'),
            'forms/catalogue.yaml: form acme.catalogue: a removal needs a new major version, 1.1.0 is not one.',
        );
        $this->assertRefused(
            $this->newVersion(['objects/tag.yaml' => static fn (array $spec): array => ['fields' => [...self::map($spec['fields']), ['id' => 'acme.tag.colour', 'name' => 'colour', 'type' => 'text', 'label' => 'Colour']]] + $spec], '1.0.1'),
            'objects/tag.yaml: field acme.tag.colour: an addition needs a new minor version, 1.0.1 is not one.',
        );
        // A label is a patch.
        $this->upgrade($this->newVersion(['objects/tag.yaml' => static fn (array $spec): array => ['label' => 'Label'] + $spec], '1.0.1'));
        self::assertSame('Label', $this->label('tag'));
    }

    public function testRunsTheMigrationStepsOfTheNewVersion(): void
    {
        $package = $this->newVersion([
            'objects/product.yaml' => static fn (array $spec): array => ['fields' => [...self::map($spec['fields']), ['id' => 'acme.product.reference', 'name' => 'reference', 'type' => 'text', 'label' => 'Reference']]] + $spec,
        ], '2.0.0');
        $manifest = new Document($package->manifest->kind, $package->manifest->id, ['migrations' => [
            '2.0.0' => [['op' => 'copy_values', 'from' => 'acme.product.sku', 'to' => 'acme.product.reference']],
            // Already installed: not run again.
            '1.0.0' => [['op' => 'copy_values', 'from' => 'acme.product.name', 'to' => 'acme.product.reference']],
        ]] + $package->manifest->spec, $package->manifest->path);

        $report = $this->upgrade(new Package($manifest, $package->documents, $package->directory));

        self::assertContains('template.yaml: migration 2.0.0: copy_values acme.product.sku to acme.product.reference', $report->applied);
        foreach (self::getContainer()->get(RecordFinderInterface::class)->each('product') as $record) {
            self::assertSame($record->values['sku'], $record->values['reference']);
        }
    }

    public function testUpgradesFromTheConsole(): void
    {
        $command = new CommandTester((new Application(self::bootKernel()))->find('metadata:template:upgrade'));

        // The example itself is 1.0.0, installed: not newer.
        self::assertSame(1, $command->execute(['template' => ExampleTemplate::DIRECTORY]));
        self::assertStringContainsString('acme/shop 1.0.0 is installed: 1.0.0 is not newer.', $command->getDisplay());
        self::assertSame(1, $command->execute(['template' => ExampleTemplate::DIRECTORY, '--strategy' => 'mine']));
        self::assertStringContainsString('--strategy is keep or overwrite.', $command->getDisplay());
    }

    /**
     * The example template as a new version: its seeds left out (upgrading them is the next
     * step of phase 4), some documents changed (a closure) or removed (null).
     *
     * @param array<string, (\Closure(array<string, mixed>): array<string, mixed>)|null> $changes
     */
    private function newVersion(array $changes, string $version = '1.1.0'): Package
    {
        $fixture = ExampleTemplate::package();
        $documents = [];
        foreach ($fixture->documents as $document) {
            if (DocumentKind::Seed === $document->kind || (\array_key_exists($document->path, $changes) && null === $changes[$document->path])) {
                continue;
            }
            $change = $changes[$document->path] ?? null;
            $documents[] = null === $change ? $document : new Document($document->kind, $document->id, $change($document->spec), $document->path);
        }
        $manifest = new Document($fixture->manifest->kind, $fixture->manifest->id, ['version' => $version] + $fixture->manifest->spec, $fixture->manifest->path);

        return new Package($manifest, $documents, $fixture->directory);
    }

    private function upgrade(Package $package, ?Strategy $strategy = null, bool $dryRun = false): UpgradeReport
    {
        return self::getContainer()->get(TemplateUpgrader::class)->upgrade($package, ExampleTemplate::DIRECTORY, $strategy, $dryRun);
    }

    private function assertRefused(Package $package, string $reason): void
    {
        try {
            $this->upgrade($package);
            self::fail(\sprintf('Refused: %s', $reason));
        } catch (UpgradeRefused $e) {
            self::assertContains($reason, $e->reasons, implode("\n", $e->reasons));
        }
    }

    private function label(string $object): string
    {
        return self::getContainer()->get(MetadataRegistryInterface::class)->getObject($object)->labelSingular;
    }

    /**
     * @return array<string, string|null>
     */
    private function italian(): array
    {
        $entries = [];
        foreach (self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it') as $entry) {
            $entries[$entry->key] = $entry->translation;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    private static function withField(mixed $fields, string $id, array $changes): array
    {
        return array_map(static fn (mixed $field): mixed => \is_array($field) && $id === $field['id'] ? $changes + $field : $field, self::map($fields));
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
