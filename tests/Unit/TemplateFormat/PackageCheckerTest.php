<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\Document;
use App\TemplateFormat\DocumentCodec;
use App\TemplateFormat\FieldTypes\FieldTypeCatalog;
use App\TemplateFormat\Package;
use App\TemplateFormat\PackageChecker;
use App\TemplateFormat\PackageDirectory;
use App\TemplateFormat\SchemaValidator;
use App\TemplateFormat\SeedRecords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackageCheckerTest extends TestCase
{
    public const string FIXTURE = __DIR__.'/Fixtures/shop';

    public function testTheExamplePackageIsValid(): void
    {
        self::assertSame([], self::checker()->check(self::fixture()));
    }

    /**
     * @param \Closure(array<mixed>): array<mixed> $change the new spec of the document
     */
    #[DataProvider('brokenPackages')]
    public function testReportsWhereAPackageIsBroken(string $path, \Closure $change, string $expected): void
    {
        $package = self::fixture();
        $changed = static fn (Document $document): Document => new Document($document->kind, $document->id, self::spec($change($document->spec)), $document->path);
        $documents = array_map(static fn (Document $document): Document => $document->path === $path ? $changed($document) : $document, $package->documents);
        $manifest = 'template.yaml' === $path ? $changed($package->manifest) : $package->manifest;

        $problems = array_map(strval(...), self::checker()->check(new Package($manifest, $documents, $package->directory)));

        self::assertContains($expected, $problems, implode("\n", $problems));
    }

    /**
     * @return iterable<string, array{string, \Closure(array<mixed>): array<mixed>, string}>
     */
    public static function brokenPackages(): iterable
    {
        $product = 'objects/product.yaml';
        /** @param array<string, mixed> $changes */
        $field = static fn (int $position, array $changes): \Closure => static function (array $spec) use ($position, $changes): array {
            $fields = self::map($spec['fields'] ?? null);
            $fields[$position] = $changes + self::map($fields[$position] ?? null);
            $spec['fields'] = $fields;

            return $spec;
        };

        yield 'a version that is not semver' => ['template.yaml', static fn (array $spec): array => ['version' => '1.0'] + $spec, 'template.yaml: spec.version: The string should match pattern: ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'];
        yield 'a manifest without license' => ['template.yaml', static fn (array $spec): array => array_diff_key($spec, ['license' => 0]), 'template.yaml: spec: The required properties (license) are missing'];
        yield 'an object without label' => [$product, static fn (array $spec): array => array_diff_key($spec, ['label' => 0]), 'objects/product.yaml: spec: The required properties (label) are missing'];
        yield 'an unknown field type' => [$product, $field(0, ['type' => 'colour']), 'objects/product.yaml: spec.fields.0.type: Unknown field type "colour" (known: text, number, boolean, date, email, tel, url, select, password, file, relation, many_to_many).'];
        yield 'an unknown setting' => [$product, $field(0, ['settings' => ['colour' => 'red']]), 'objects/product.yaml: spec.fields.0.settings.colour: Unknown setting (type "text" has maxLength, multiline, pattern, patternMessage).'];
        yield 'a setting out of range' => [$product, $field(6, ['settings' => ['maxSize' => 500]]), 'objects/product.yaml: spec.fields.6.settings.maxSize: Must be between 1 and 100.'];
        yield 'a choice outside its values' => [$product, $field(4, ['settings' => ['target' => 'acme.category', 'onDelete' => 'archive']]), 'objects/product.yaml: spec.fields.4.settings.onDelete: Must be one of set_null, restrict, cascade.'];
        yield 'a relation without target' => [$product, $field(4, ['settings' => []]), 'objects/product.yaml: spec.fields.4.settings.target: Required: the id of an object of the template.'];
        yield 'a target outside the template' => [$product, $field(5, ['settings' => ['target' => 'acme.colour']]), 'objects/product.yaml: spec.fields.5.settings.target: No object of the template has the id "acme.colour".'];
        yield 'a cascade through a field of another object' => [$product, $field(4, ['settings' => ['target' => 'acme.category', 'dependsOn' => 'acme.product.kind', 'dependsVia' => 'acme.tag.name']]), 'objects/product.yaml: spec.fields.4.settings.dependsVia: "acme.tag.name" is a field of "acme.tag", not of "acme.category".'];
        yield 'a condition on itself' => [$product, $field(3, ['condition' => ['field' => 'acme.product.seats', 'values' => ['4']]]), 'objects/product.yaml: spec.fields.3.condition.field: A field cannot depend on itself.'];
        yield 'a label field of another object' => [$product, static fn (array $spec): array => ['label_field' => 'acme.tag.name'] + $spec, 'objects/product.yaml: spec.label_field: "acme.tag.name" is a field of "acme.tag", not of "acme.product".'];
        yield 'two fields with one name' => [$product, $field(1, ['name' => 'name']), 'objects/product.yaml: spec.fields.1.name: Another field of the object has this name.'];
        yield 'a field id used twice' => ['objects/tag.yaml', $field(0, ['id' => 'acme.product.name']), 'objects/tag.yaml: spec.fields.0.id: Already the id of a field of "acme.product".'];
        yield 'two objects with one name' => ['objects/tag.yaml', static fn (array $spec): array => ['name' => 'product'] + $spec, 'objects/tag.yaml: spec.name: Same as in objects/product.yaml.'];
        yield 'a view of no object' => ['views/product.grid.main.yaml', static fn (array $spec): array => ['object' => 'acme.order'] + $spec, 'views/product.grid.main.yaml: spec.object: No object of the template has the id "acme.order".'];
        $inline = static fn (array $records): \Closure => static fn (array $spec): array => ['records' => $records] + array_diff_key($spec, ['source' => true]);
        $chair = ['sku' => 'OSL-01', 'name' => 'Oslo chair', 'category' => 'Chairs'];
        yield 'a seed keyed on a field that is not unique' => ['seeds/product.yaml', static fn (array $spec): array => ['key' => 'name'] + $spec, 'seeds/product.yaml: spec.key: "name" is not a unique field: a key names one record.'];
        yield 'a seed of no object' => ['seeds/tag.yaml', static fn (array $spec): array => ['object' => 'acme.colour'] + $spec, 'seeds/tag.yaml: spec.object: No object of the template has the id "acme.colour".'];
        yield 'a seed with both records and a source' => ['seeds/tag.yaml', static fn (array $spec): array => ['source' => 'seeds/tags.csv'] + $spec, 'seeds/tag.yaml: spec: A seed has its records inline (records) or in a CSV file (source): one of the two.'];
        yield 'a record with an unknown field' => ['seeds/category.yaml', static fn (array $spec): array => ['records' => [['title' => 'Chairs', 'colour' => 'red']]] + $spec, 'seeds/category.yaml: spec.records.0.colour: The object has no field "colour".'];
        yield 'two records with one key' => ['seeds/category.yaml', static fn (array $spec): array => ['records' => [['title' => 'Chairs'], ['title' => 'Chairs']]] + $spec, 'seeds/category.yaml: spec.records.1.title: Another record has the key "Chairs".'];
        yield 'a record without its key' => ['seeds/category.yaml', static fn (array $spec): array => ['records' => [['title' => ' ']]] + $spec, 'seeds/category.yaml: spec.records.0.title: The key is missing: every record has one.'];
        yield 'a relation to no record' => ['seeds/product.yaml', $inline([['category' => 'Sofas'] + $chair]), 'seeds/product.yaml: spec.records.0.category: No record of the seed of "acme.category" has the key "Sofas".'];
        yield 'a link to no record' => ['seeds/product.yaml', $inline([['tags' => ['new', 'oak']] + $chair]), 'seeds/product.yaml: spec.records.0.tags: No record of the seed of "acme.tag" has the key "oak".'];
        yield 'a file outside the package' => ['seeds/product.yaml', $inline([['photo' => '../../../composer.json'] + $chair]), 'seeds/product.yaml: spec.records.0.photo: No file "../../../composer.json" in the package.'];
        yield 'a source the package lacks' => ['seeds/product.yaml', static fn (array $spec): array => ['source' => 'seeds/missing.csv'] + $spec, 'seeds/product.yaml: spec.source: No file "seeds/missing.csv" in the package.'];
        yield 'a section via a field of another object' => ['forms/catalogue.yaml', static function (array $spec): array {
            $definition = self::map($spec['definition'] ?? null);
            $sections = self::map($definition['sections'] ?? null);
            $sections[0] = ['via' => 'acme.category.title'] + self::map($sections[0] ?? null);
            $definition['sections'] = $sections;
            $spec['definition'] = $definition;

            return $spec;
        }, 'forms/catalogue.yaml: spec.definition.sections.0.via: "acme.category.title" is a field of "acme.category", not of "acme.product".'];
    }

    public function testADocumentBreakingItsSchemaIsNotCheckedFurther(): void
    {
        $package = self::fixture();
        $documents = array_map(
            static fn (Document $document): Document => 'objects/product.yaml' === $document->path ? new Document($document->kind, $document->id, ['fields' => 'none'] + $document->spec, $document->path) : $document,
            $package->documents,
        );

        $problems = array_map(strval(...), self::checker()->check(new Package($package->manifest, $documents, $package->directory)));

        // The schema says what is wrong; the references into the broken object are then unknown.
        self::assertContains('objects/product.yaml: spec.fields: The data (string) must match the type: array', $problems, implode("\n", $problems));
        self::assertContains('forms/catalogue.yaml: spec.definition.sections.0.object: Must be the id of an object of the template, "acme.product" is none.', $problems);
    }

    public static function fixture(): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read(self::FIXTURE);
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
     * @param array<mixed> $spec
     *
     * @return array<string, mixed>
     */
    private static function spec(array $spec): array
    {
        $keyed = [];
        foreach ($spec as $key => $value) {
            self::assertIsString($key);
            $keyed[$key] = $value;
        }

        return $keyed;
    }

    private static function checker(): PackageChecker
    {
        return new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords());
    }
}
