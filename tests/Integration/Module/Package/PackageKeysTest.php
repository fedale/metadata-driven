<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Upgrade\PackageUpgrader;
use App\Module\Package\Domain\Exception\UpgradeRefused;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Tests\Support\ExamplePackage;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\Package;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The key of a package's objects (format 2.4): installed as the package says it, exported as
 * the instance holds it, never changed by an upgrade.
 */
final class PackageKeysTest extends KernelTestCase
{
    public function testInstallsAnObjectWithTheKeyThePackageSays(): void
    {
        $this->install($this->withProductKey(ExamplePackage::package(), 'integer'));

        self::assertSame(KeyType::Integer, $this->registry()->getObject('product')->keyType);
        self::assertSame(KeyType::Uuid, $this->registry()->getObject('category')->keyType);
        // The seeds come in all the same: they name records by a field, not by id.
        $products = iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('product'), false);
        self::assertCount(2, $products);
        foreach ($products as $product) {
            self::assertMatchesRegularExpression('/^\d+$/', $product->id);
            self::assertNotNull($product->get('category'));
        }
    }

    public function testExportsAnIntegerKeyAndLeavesTheDefaultOut(): void
    {
        $this->install($this->withProductKey(ExamplePackage::package(), 'integer'));

        $owned = self::getContainer()->get(PackageExporter::class)->ownedState('acme/shop');

        self::assertSame('integer', $this->spec($owned, 'objects/product.yaml')['key'] ?? null);
        self::assertArrayNotHasKey('key', $this->spec($owned, 'objects/category.yaml'));
    }

    public function testAnUpgradeAddsAnObjectWithItsKeyAndNeverChangesOne(): void
    {
        $this->install(ExamplePackage::package());

        $ticket = new Document(DocumentKind::Object, 'acme.ticket', ['name' => 'ticket', 'label' => 'Ticket', 'plural' => 'Tickets', 'key' => 'integer', 'fields' => []], 'objects/ticket.yaml');
        $next = $this->version(ExamplePackage::package(), '1.1.0');
        $this->upgrade(new Package($next->manifest, [...$next->documents, $ticket], $next->directory));
        self::assertSame(KeyType::Integer, $this->registry()->getObject('ticket')->keyType);

        // Not even in a major version, which the format allows: the tables pointing at the
        // object hold keys of its type.
        try {
            $this->upgrade($this->withProductKey($this->version(ExamplePackage::package(), '2.0.0'), 'integer'));
            self::fail('The key of an object changed.');
        } catch (UpgradeRefused $e) {
            self::assertContains('objects/product.yaml: object acme.product: its key changed from uuid to integer: the key of an object is fixed once it exists.', array_map(strval(...), $e->reasons), implode("\n", $e->reasons));
        }
        self::assertSame(KeyType::Uuid, $this->registry()->getObject('product')->keyType);
    }

    private function install(Package $package): void
    {
        self::getContainer()->get(PackageInstaller::class)->install($package, ExamplePackage::DIRECTORY);
    }

    private function upgrade(Package $package): void
    {
        self::getContainer()->get(PackageUpgrader::class)->upgrade($package, ExamplePackage::DIRECTORY);
    }

    private function withProductKey(Package $package, string $key): Package
    {
        return new Package($package->manifest, array_map(
            static fn (Document $document): Document => 'objects/product.yaml' === $document->path ? new Document($document->kind, $document->id, ['key' => $key] + $document->spec, $document->path) : $document,
            $package->documents,
        ), $package->directory);
    }

    private function version(Package $package, string $version): Package
    {
        $manifest = $package->manifest;

        return new Package(new Document($manifest->kind, $manifest->id, ['version' => $version] + $manifest->spec, $manifest->path), $package->documents, $package->directory);
    }

    /**
     * @return array<mixed>
     */
    private function spec(Package $package, string $path): array
    {
        foreach ($package->documents as $document) {
            if ($path === $document->path) {
                return $document->spec;
            }
        }
        self::fail(\sprintf('No %s in the package.', $path));
    }

    private function registry(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }
}
