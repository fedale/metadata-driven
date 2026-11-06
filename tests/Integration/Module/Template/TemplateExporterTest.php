<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Template\Application\Command\ExportTemplateCommand;
use App\Module\Template\Application\TemplateExporter;
use App\Module\Template\Contract\Exception\TemplateException;
use App\Tests\Support\RemovesDirectories;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exports a model built through the real metadata API, and reads the template back.
 */
final class TemplateExporterTest extends KernelTestCase
{
    use RemovesDirectories;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-export-'.bin2hex(random_bytes(4));
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);

        $metadata->createObject('category', 'Category', 'Categories');
        $metadata->createField('category', 'title', 'text', 'Title', required: true, unique: true, settings: ['maxLength' => 100]);
        $metadata->createObject('tag', 'Tag', 'Tags');
        $metadata->createField('tag', 'name', 'text', 'Name', required: true);
        $metadata->createObject('product', 'Product', 'Products', 'box');
        $metadata->createField('product', 'name', 'text', 'Name', required: true);
        $metadata->createField('product', 'sku', 'text', 'SKU', unique: true, settings: ['maxLength' => 20]);
        $metadata->createField('product', 'kind', 'select', 'Kind', settings: ['options' => [['value' => 'chair', 'label' => 'Chair'], ['value' => 'table', 'label' => 'Table']]]);
        $metadata->createField('product', 'seats', 'number', 'Seats', settings: ['min' => 1]);
        $metadata->configureFieldCondition('product', 'seats', 'kind', ['chair']);
        $metadata->createField('product', 'category', 'relation', 'Category', settings: ['target' => 'category', 'onDelete' => 'restrict', 'inverseName' => 'products', 'inverseLabel' => 'Products']);
        $metadata->createField('product', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag']);
        $metadata->createField('product', 'photo', 'file', 'Photo', settings: ['image' => true, 'maxSize' => 5]);
        $metadata->configureLabel('product', 'name');
        $metadata->configureSearch('product', 'phrase', ['name', 'sku']);
        $metadata->addUniqueTogether('product', ['category', 'name']);
        $metadata->translate('en', 'text.identity', 'Identity');
        $metadata->translate('it', 'text.identity', 'Identità');
        $metadata->translate('it', 'object.product.singular', 'Prodotto');
        $metadata->translate('it', 'field.product.name', 'Nome');
        $metadata->translate('it', 'field.tag.name', 'Nome');
        $metadata->saveView('product', ViewKind::Form, 'main', '<fieldset><legend>{t:identity}</legend>{name} {sku}</fieldset>', isDefault: true);
        $metadata->saveView('product', ViewKind::Grid, 'main', "columns:\n  - name\n  - sku\n", isDefault: true);
        $metadata->saveForm('catalogue', "label: Catalogue entry\nobject: category\nsections:\n  - { name: products, label: Products, object: product, via: category, mode: many }\n");
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testExportsTheModelWithIdsForEveryReference(): void
    {
        $package = $this->export();

        self::assertSame('acme/shop', $package->name());
        self::assertSame([
            'objects/category.yaml',
            'objects/tag.yaml',
            'objects/product.yaml',
            'views/product.form.main.yaml',
            'views/product.grid.main.yaml',
            'forms/catalogue.yaml',
            'translations/en.yaml',
            'translations/it.yaml',
        ], array_map(static fn (Document $document): string => $document->path, $package->documents));

        $product = $this->document($package, 'objects/product.yaml');
        self::assertSame('acme.product', $product->id);
        self::assertSame('box', $product->spec['icon']);
        self::assertSame('acme.product.name', $product->spec['label_field']);
        self::assertSame(['handler' => 'phrase', 'fields' => ['acme.product.name', 'acme.product.sku']], $product->spec['search']);
        self::assertSame([['acme.product.category', 'acme.product.name']], $product->spec['unique_together']);
        $fields = self::fieldsBy('name', $product);
        // Only what differs from the defaults, and objects and fields by id.
        self::assertSame(['id' => 'acme.product.sku', 'name' => 'sku', 'type' => 'text', 'label' => 'SKU', 'unique' => true, 'settings' => ['maxLength' => 20]], $fields['sku']);
        self::assertSame(['target' => 'acme.category', 'onDelete' => 'restrict', 'inverseName' => 'products', 'inverseLabel' => 'Products'], $fields['category']['settings'] ?? null);
        self::assertSame(['target' => 'acme.tag'], $fields['tags']['settings'] ?? null);
        self::assertSame(['field' => 'acme.product.kind', 'values' => ['chair']], $fields['seats']['condition'] ?? null);
        self::assertArrayNotHasKey('settings', $fields['name']);

        $definition = self::map($this->document($package, 'forms/catalogue.yaml')->spec['definition']);
        self::assertSame('acme.category', $definition['object']);
        self::assertSame(['name' => 'products', 'label' => 'Products', 'object' => 'acme.product', 'via' => 'acme.product.category', 'mode' => 'many'], self::map($definition['sections'])[0]);

        // The text of the layout, in every language; the labels, in the other languages only.
        self::assertSame(['text.identity' => 'Identity'], $this->document($package, 'translations/en.yaml')->spec['entries']);
        self::assertSame([
            'field.product.name' => 'Nome',
            'field.tag.name' => 'Nome',
            'object.product.singular' => 'Prodotto',
            'text.identity' => 'Identità',
        ], $this->document($package, 'translations/it.yaml')->spec['entries']);
    }

    public function testWritesATemplateThatReadsBackValid(): void
    {
        $package = $this->export();
        $directories = self::getContainer()->get(PackageDirectory::class);
        $directories->write($package, $this->directory);

        $read = $directories->read($this->directory);

        self::assertSame([], self::getContainer()->get(PackageChecker::class)->check($read));
        self::assertEquals(self::byPath($package), self::byPath($read));
    }

    public function testARenamedItemKeepsItsId(): void
    {
        $this->export();
        self::getContainer()->get(MetadataManagerInterface::class)->renameField('product', 'sku', 'code');
        self::getContainer()->get(MetadataManagerInterface::class)->renameObject('tag', 'label');

        $package = $this->export(['category', 'label', 'product']);

        $fields = self::fieldsBy('id', $this->document($package, 'objects/product.yaml'));
        self::assertSame('code', $fields['acme.product.sku']['name'] ?? null);
        self::assertSame(['target' => 'acme.tag'], $fields['acme.product.tags']['settings'] ?? null);
        self::assertSame('label', $this->document($package, 'objects/label.yaml')->spec['name']);
        self::assertSame('acme.tag', $this->document($package, 'objects/label.yaml')->id);
    }

    public function testRefusesAnExportThatPointsOutsideItself(): void
    {
        foreach ([
            [['product'], [], 'Object "product" points at "category" (category.target), which is not exported: add it to the objects.'],
            [['product', 'tag'], ['catalogue'], 'Form "catalogue" writes object "category", which is not exported: add it to the objects.'],
        ] as [$objects, $forms, $message]) {
            try {
                $this->export($objects, $forms);
                self::fail($message);
            } catch (TemplateException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testExportsAndValidatesFromTheConsole(): void
    {
        $application = new Application(self::bootKernel());
        $export = new CommandTester($application->find('metadata:template:export'));
        $export->execute(['name' => 'acme/shop', 'release' => '1.2.0', 'directory' => $this->directory, '--object' => ['category', 'tag', 'product'], '--form' => ['catalogue'], '--license' => 'MIT']);
        $export->assertCommandIsSuccessful();
        self::assertStringContainsString('Template acme/shop 1.2.0 written to', $export->getDisplay());

        $validate = new CommandTester($application->find('metadata:template:validate'));
        $validate->execute(['directory' => $this->directory]);
        $validate->assertCommandIsSuccessful();
        self::assertStringContainsString('follows the format (8 documents)', $validate->getDisplay());

        file_put_contents($this->directory.'/objects/tag.yaml', str_replace('type: text', 'type: colour', (string) file_get_contents($this->directory.'/objects/tag.yaml')));
        self::assertSame(1, $validate->execute(['directory' => $this->directory]));
        self::assertStringContainsString('objects/tag.yaml: spec.fields.0.type: Unknown field type "colour"', $validate->getDisplay());
    }

    /**
     * @param list<string> $objects
     * @param list<string> $forms
     */
    private function export(array $objects = ['category', 'tag', 'product'], array $forms = ['catalogue']): Package
    {
        return self::getContainer()->get(TemplateExporter::class)->export(new ExportTemplateCommand('acme/shop', '1.0.0', $objects, $forms, license: 'MIT'));
    }

    private function document(Package $package, string $path): Document
    {
        foreach ($package->documents as $document) {
            if ($document->path === $path) {
                return $document;
            }
        }
        self::fail(\sprintf('No document %s.', $path));
    }

    /**
     * The fields of an object document, by name or by id.
     *
     * @return array<array-key, array<mixed>>
     */
    private static function fieldsBy(string $key, Document $object): array
    {
        $fields = [];
        foreach (self::map($object->spec['fields']) as $field) {
            $field = self::map($field);
            self::assertIsString($field[$key]);
            $fields[$field[$key]] = $field;
        }

        return $fields;
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * The documents by path, in a stable order: reading a directory sorts them.
     *
     * @return array<string, Document>
     */
    private static function byPath(Package $package): array
    {
        $documents = [];
        foreach ([$package->manifest, ...$package->documents] as $document) {
            $documents[$document->path] = $document;
        }
        ksort($documents);

        self::assertNotEmpty($documents);
        self::assertSame(DocumentKind::Template, $package->manifest->kind);

        return $documents;
    }
}
