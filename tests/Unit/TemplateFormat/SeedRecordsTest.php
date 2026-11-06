<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\Document;
use App\TemplateFormat\DocumentKind;
use App\TemplateFormat\InvalidPackage;
use App\TemplateFormat\Package;
use App\TemplateFormat\SeedRecords;
use App\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\TestCase;

final class SeedRecordsTest extends TestCase
{
    use RemovesDirectories;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-seed-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/seeds', 0o775, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testReadsACsvFileAsSpreadsheetsWriteIt(): void
    {
        // A byte order mark, quotes doubled inside quotes, an empty cell.
        file_put_contents($this->directory.'/seeds/products.csv', "\xEF\xBB\xBFsku,name,seats\nOSL-01,\"Oslo \"\"lounge\"\" chair\",4\nBRG-02,Bergen table,\n");

        self::assertSame([
            ['sku' => 'OSL-01', 'name' => 'Oslo "lounge" chair', 'seats' => '4'],
            ['sku' => 'BRG-02', 'name' => 'Bergen table', 'seats' => null],
        ], (new SeedRecords())->of($this->package(), $this->seed()));
    }

    public function testReportsALineThatDoesNotFitTheHeader(): void
    {
        file_put_contents($this->directory.'/seeds/products.csv', "sku,name\nOSL-01,Oslo chair,4\n");

        try {
            (new SeedRecords())->of($this->package(), $this->seed());
            self::fail('A line longer than the header must be refused.');
        } catch (InvalidPackage $e) {
            self::assertSame(['seeds/product.yaml: spec.source: Line 2 has 3 values, the header 2.'], array_map(strval(...), $e->problems));
        }
    }

    public function testSplitsTheValuesOfAFieldHoldingSeveral(): void
    {
        self::assertSame(['new', 'wood'], SeedRecords::list('new| wood |'));
        self::assertSame(['new', 'wood'], SeedRecords::list(['new', 'wood']));
        self::assertSame([], SeedRecords::list(null));
    }

    private function package(): Package
    {
        return new Package(new Document(DocumentKind::Template, 'acme/shop', [], 'template.yaml'), [], $this->directory);
    }

    private function seed(): Document
    {
        return new Document(DocumentKind::Seed, 'acme.seed.product', ['object' => 'acme.product', 'key' => 'sku', 'source' => 'seeds/products.csv'], 'seeds/product.yaml');
    }
}
