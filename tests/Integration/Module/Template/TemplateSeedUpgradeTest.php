<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Template\Application\TemplateInstaller;
use App\Module\Template\Application\Upgrade\Strategy;
use App\Module\Template\Application\Upgrade\TemplateUpgrader;
use App\Module\Template\Application\Upgrade\UpgradeReport;
use App\Module\Template\Domain\Exception\UpgradeRefused;
use App\TemplateFormat\Document;
use App\TemplateFormat\Package;
use App\Tests\Unit\TemplateFormat\PackageCheckerTest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Upgrades the records of the example template's seeds: its products made a managed seed, its
 * tags left a `once` one.
 */
final class TemplateSeedUpgradeTest extends KernelTestCase
{
    private const array CHAIR = ['sku' => 'OSL-01', 'name' => 'Oslo chair', 'kind' => 'chair', 'seats' => '4', 'category' => 'Chairs', 'tags' => 'new|wood', 'photo' => 'files/products/oslo.png'];
    private const array TABLE = ['sku' => 'BRG-02', 'name' => 'Bergen table', 'kind' => 'table', 'category' => 'Tables', 'tags' => 'wood'];

    protected function setUp(): void
    {
        self::getContainer()->get(TemplateInstaller::class)->install($this->version('1.0.0', [self::CHAIR, self::TABLE]), PackageCheckerTest::FIXTURE);
    }

    public function testKeepsTheRecordsInLineWithTheNewVersion(): void
    {
        $report = $this->upgrade($this->version('1.1.0', [
            ['name' => 'Oslo armchair'] + self::CHAIR,
            ['sku' => 'KAR-03', 'name' => 'Karl stool', 'kind' => 'chair', 'seats' => 1, 'category' => 'Chairs', 'tags' => ['new']],
        ]));

        self::assertEqualsCanonicalizing([
            'seeds/product.yaml: update record OSL-01',
            'seeds/product.yaml: create record KAR-03',
            'seeds/product.yaml: delete record BRG-02',
        ], $report->applied);
        $products = $this->products();
        self::assertSame(['KAR-03', 'OSL-01'], array_keys($products));
        self::assertSame('Oslo armchair', $products['OSL-01']->values['name']);
        self::assertSame(1, $products['KAR-03']->values['seats']);
    }

    public function testStopsAtARecordTheUserChangedToo(): void
    {
        $this->rename('OSL-01', 'Oslo deluxe');
        $newVersion = $this->version('1.1.0', [['name' => 'Oslo armchair'] + self::CHAIR, self::TABLE]);

        try {
            $this->upgrade($newVersion);
            self::fail('A record both changed must stop the upgrade.');
        } catch (UpgradeRefused $e) {
            self::assertSame(['seeds/product.yaml: record OSL-01, changed in this version, was changed here too'], $e->reasons);
        }

        self::assertSame(['seeds/product.yaml: record OSL-01, changed in this version, was changed here too'], $this->upgrade($newVersion, Strategy::Keep)->kept);
        self::assertSame('Oslo deluxe', $this->products()['OSL-01']->values['name']);
    }

    public function testTheNewVersionWinsWhenAskedTo(): void
    {
        $this->rename('OSL-01', 'Oslo deluxe');

        $this->upgrade($this->version('1.1.0', [['name' => 'Oslo armchair'] + self::CHAIR, self::TABLE]), Strategy::Overwrite);

        self::assertSame('Oslo armchair', $this->products()['OSL-01']->values['name']);
    }

    public function testARecordTheUserChangedIsNotRemoved(): void
    {
        $this->rename('BRG-02', 'Bergen long table');

        $this->assertConflict($this->version('1.1.0', [self::CHAIR]), 'seeds/product.yaml: record BRG-02, removed in this version, was changed here');
    }

    public function testLeavesTheRecordsOfAOnceSeedToTheUser(): void
    {
        $newVersion = $this->version('1.1.0', [self::CHAIR, self::TABLE], tags: [['name' => 'new'], ['name' => 'wood'], ['name' => 'oak']]);

        self::assertSame([], $this->upgrade($newVersion)->applied);
        $tags = array_map(static fn (RecordOutput $tag): mixed => $tag->values['name'], iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('tag'), false));
        self::assertEqualsCanonicalizing(['new', 'wood'], $tags);
    }

    /**
     * The example template, its products a managed seed of these records, inline.
     *
     * @param list<array<string, mixed>>      $products
     * @param list<array<string, mixed>>|null $tags     the tags' records; null for the example's
     */
    private function version(string $version, array $products, ?array $tags = null): Package
    {
        $fixture = PackageCheckerTest::fixture();
        $documents = [];
        foreach ($fixture->documents as $document) {
            $spec = match ($document->path) {
                'seeds/product.yaml' => ['object' => 'acme.product', 'key' => 'sku', 'mode' => 'managed', 'records' => $products],
                'seeds/tag.yaml' => null === $tags ? $document->spec : ['records' => $tags] + $document->spec,
                default => $document->spec,
            };
            $documents[] = new Document($document->kind, $document->id, $spec, $document->path);
        }
        $manifest = new Document($fixture->manifest->kind, $fixture->manifest->id, ['version' => $version] + $fixture->manifest->spec, $fixture->manifest->path);

        return new Package($manifest, $documents, $fixture->directory);
    }

    private function upgrade(Package $package, ?Strategy $strategy = null): UpgradeReport
    {
        return self::getContainer()->get(TemplateUpgrader::class)->upgrade($package, PackageCheckerTest::FIXTURE, $strategy);
    }

    private function assertConflict(Package $package, string $conflict): void
    {
        try {
            $this->upgrade($package);
            self::fail(\sprintf('Conflict: %s', $conflict));
        } catch (UpgradeRefused $e) {
            self::assertTrue($e->conflicts);
            self::assertContains($conflict, $e->reasons, implode("\n", $e->reasons));
        }
    }

    private function rename(string $sku, string $name): void
    {
        self::getContainer()->get(RecordManagerInterface::class)->update($this->products()[$sku]->id, new RecordInput('product', ['name' => $name]));
    }

    /**
     * @return array<string, RecordOutput> by SKU, sorted
     */
    private function products(): array
    {
        $products = [];
        foreach (self::getContainer()->get(RecordFinderInterface::class)->each('product') as $record) {
            $sku = $record->values['sku'] ?? null;
            self::assertIsString($sku);
            $products[$sku] = $record;
        }
        ksort($products);

        return $products;
    }
}
