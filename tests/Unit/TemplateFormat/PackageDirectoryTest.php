<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\Document;
use App\TemplateFormat\DocumentCodec;
use App\TemplateFormat\InvalidPackage;
use App\TemplateFormat\Package;
use App\TemplateFormat\PackageDirectory;
use App\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\TestCase;

final class PackageDirectoryTest extends TestCase
{
    use RemovesDirectories;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-template-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testReadsTheDocumentsTheManifestLists(): void
    {
        $package = PackageCheckerTest::fixture();

        self::assertSame('acme/shop', $package->name());
        self::assertSame('1.0.0', $package->version());
        self::assertSame([
            'forms/catalogue.yaml',
            'objects/category.yaml',
            'objects/product.yaml',
            'objects/tag.yaml',
            'translations/it.yaml',
            'views/product.form.main.yaml',
            'views/product.grid.main.yaml',
        ], array_map(static fn (Document $document): string => $document->path, $package->documents));
    }

    public function testWritesAPackageThatReadsBackTheSame(): void
    {
        $directories = new PackageDirectory(new DocumentCodec());
        $package = PackageCheckerTest::fixture();

        $directories->write($package, $this->directory);

        self::assertEquals($package, $directories->read($this->directory));
    }

    public function testWritesIntoANewOrEmptyDirectoryOnly(): void
    {
        $directories = new PackageDirectory(new DocumentCodec());
        $directories->write(PackageCheckerTest::fixture(), $this->directory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not empty');
        $directories->write(PackageCheckerTest::fixture(), $this->directory);
    }

    public function testAResourceStaysInsideThePackage(): void
    {
        $package = PackageCheckerTest::fixture();
        $manifest = new Document($package->manifest->kind, $package->manifest->id, ['resources' => ['../secrets/*.yaml']] + $package->manifest->spec, $package->manifest->path);
        $directories = new PackageDirectory(new DocumentCodec());
        $directories->write(new Package($manifest, []), $this->directory);

        try {
            $directories->read($this->directory);
            self::fail('A resource outside the package must be refused.');
        } catch (InvalidPackage $e) {
            self::assertSame(['template.yaml: spec.resources.0: A resource stays inside the package.'], array_map(strval(...), $e->problems));
        }
    }

    public function testAPackageHasAManifest(): void
    {
        $this->expectException(InvalidPackage::class);
        $this->expectExceptionMessage('No manifest in');

        (new PackageDirectory(new DocumentCodec()))->read(__DIR__);
    }
}
