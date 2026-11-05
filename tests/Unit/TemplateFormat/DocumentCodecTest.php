<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\Document;
use App\TemplateFormat\DocumentCodec;
use App\TemplateFormat\DocumentKind;
use App\TemplateFormat\InvalidPackage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentCodecTest extends TestCase
{
    public function testWritesAndReadsADocumentBack(): void
    {
        $codec = new DocumentCodec();
        $view = new Document(DocumentKind::View, 'acme.product.form.main', [
            'object' => 'acme.product',
            'kind' => 'form',
            'name' => 'main',
            'default' => true,
            'template' => "<fieldset>{name}</fieldset>\n<p>{sku}</p>\n",
        ], 'views/product.form.main.yaml');

        $yaml = $codec->encode($view);

        self::assertStringStartsWith("format: metadata-driven/v1\nkind: View\nid: acme.product.form.main\n", $yaml);
        // A layout stays readable: a literal block, not an escaped string.
        self::assertStringContainsString("  template: |\n    <fieldset>{name}</fieldset>\n    <p>{sku}</p>\n", $yaml);
        self::assertEquals($view, $codec->decode($yaml, 'views/product.form.main.yaml'));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('brokenEnvelopes')]
    public function testReportsABrokenEnvelope(string $yaml, array $expected): void
    {
        try {
            (new DocumentCodec())->decode($yaml, 'objects/product.yaml');
            self::fail('A broken envelope must be refused.');
        } catch (InvalidPackage $e) {
            self::assertSame($expected, array_map(strval(...), $e->problems));
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function brokenEnvelopes(): iterable
    {
        yield 'not YAML' => ["kind: [Object\n", ['objects/product.yaml: Not valid YAML: Malformed inline YAML string at line 2.']];
        yield 'a list' => ["- a\n- b\n", ['objects/product.yaml: A document is a map with the keys format, kind, id and spec.']];
        yield 'everything wrong' => ["format: v2\nkind: Thing\nid: Product\nspec: [1]\nname: product\n", [
            'objects/product.yaml: name: Unknown key: a document has format, kind, id and spec only.',
            'objects/product.yaml: format: Must be "metadata-driven/v1".',
            'objects/product.yaml: kind: Must be one of Template, Object, Form, View, Translations, Seed.',
            'objects/product.yaml: id: Lowercase dot-separated segments, at least two, the first naming the vendor: e.g. acme.product.',
            'objects/product.yaml: spec: Must be a map.',
        ]];
        yield 'a manifest named like an item' => ["format: metadata-driven/v1\nkind: Template\nid: acme.shop\nspec: {}\n", [
            'objects/product.yaml: id: The id of the manifest is the name of the template, vendor/name: lowercase letters, digits and hyphens.',
        ]];
    }
}
