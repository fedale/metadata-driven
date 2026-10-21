<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Application;

use App\Module\FieldType\Application\FieldTypeRegistry;
use App\Module\FieldType\Application\MetadataFieldTypeCatalog;
use App\Module\FieldType\Contract\Exception\UnknownFieldType;
use App\Module\FieldType\Domain\Type\BooleanFieldType;
use App\Module\FieldType\Domain\Type\TextFieldType;
use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    public function testIndexesTypesByName(): void
    {
        $registry = new FieldTypeRegistry([new TextFieldType(), new BooleanFieldType()]);

        self::assertSame(['boolean', 'text'], array_keys($registry->all()));
        self::assertTrue($registry->has('text'));
        self::assertInstanceOf(TextFieldType::class, $registry->get('text'));
    }

    public function testRejectsUnknownType(): void
    {
        $this->expectException(UnknownFieldType::class);

        (new FieldTypeRegistry([]))->get('text');
    }

    public function testRejectsDuplicateNames(): void
    {
        $this->expectException(\LogicException::class);

        new FieldTypeRegistry([new TextFieldType(), new TextFieldType()]);
    }

    public function testCatalogMergesDefaultsIntoSettings(): void
    {
        $catalog = new MetadataFieldTypeCatalog(new FieldTypeRegistry([new TextFieldType()]));

        self::assertSame(['maxLength' => 80, 'multiline' => false], $catalog->resolveSettings('text', ['maxLength' => 80]));
    }

    public function testCatalogThrowsWithAllErrors(): void
    {
        $catalog = new MetadataFieldTypeCatalog(new FieldTypeRegistry([new TextFieldType()]));

        try {
            $catalog->resolveSettings('text', ['maxLength' => -1, 'multiline' => 'no']);
            self::fail('InvalidFieldSettings expected');
        } catch (InvalidFieldSettings $e) {
            self::assertSame('text', $e->type);
            self::assertCount(2, $e->errors);
        }
    }
}
