<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\FieldTypes\FieldOwner;
use App\TemplateFormat\FieldTypes\FieldTypeCatalog;
use App\TemplateFormat\FieldTypes\ReferenceKind;
use App\TemplateFormat\FieldTypes\SettingReference;
use PHPUnit\Framework\TestCase;

/**
 * The catalog itself; FieldTypeCatalogMatchesTheAppTest checks it against the app's types.
 */
final class FieldTypeCatalogTest extends TestCase
{
    public function testWritesOnlyTheSettingsThatDifferFromTheirDefault(): void
    {
        $catalog = new FieldTypeCatalog();

        self::assertSame(['maxLength' => 100], $catalog->withoutDefaults('text', ['maxLength' => 100, 'multiline' => false, 'pattern' => null, 'patternMessage' => null]));
        self::assertSame([], $catalog->withoutDefaults('email', []));
    }

    public function testFindsTheSettingsThatNameOtherItems(): void
    {
        $references = (new FieldTypeCatalog())->references('many_to_many', [
            'target' => 'acme.tag',
            'through' => 'acme.product_tag',
            'throughSource' => 'acme.product_tag.product',
            'throughTarget' => 'acme.product_tag.tag',
            'inverseName' => 'products',
        ]);

        self::assertEquals([
            new SettingReference('target', ReferenceKind::Object, 'acme.tag'),
            new SettingReference('through', ReferenceKind::Object, 'acme.product_tag'),
            new SettingReference('throughSource', ReferenceKind::Field, 'acme.product_tag.product', FieldOwner::Through),
            new SettingReference('throughTarget', ReferenceKind::Field, 'acme.product_tag.tag', FieldOwner::Through),
        ], $references);
    }

    public function testChecksTheShapeOfSelectOptions(): void
    {
        $catalog = new FieldTypeCatalog();

        self::assertSame([], $catalog->check('select', ['options' => [['value' => 'chair', 'label' => 'Chair']]]));
        self::assertSame(
            ['options' => ['Every option is a map with a value (a non-empty string) and a label, nothing else.']],
            $catalog->check('select', ['options' => [['value' => 'chair']]]),
        );
        self::assertSame(['' => ['Unknown setting: type "email" has no settings.']], array_slice($catalog->check('email', ['' => 1]), 0, 1));
    }
}
