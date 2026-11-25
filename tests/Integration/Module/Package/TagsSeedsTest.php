<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Package\Application\Command\ExportPackageCommand;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Upgrade\PackageUpgrader;
use App\Module\Package\Domain\Exception\InvalidExport;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentCodec;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\PackageDirectory;
use Fedale\PackageFormat\SeedRecords;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The labels of a tags field in a package's seeds (tests/Fixtures/packages/helpdesk): separated
 * by "|" in a CSV file, a list in YAML; compared as a set at an upgrade.
 */
final class TagsSeedsTest extends KernelTestCase
{
    private const string DIRECTORY = __DIR__.'/../../../Fixtures/packages/helpdesk';

    protected function setUp(): void
    {
        self::getContainer()->get(PackageInstaller::class)->install(self::package(), self::DIRECTORY);
    }

    public function testACsvSeedSeparatesTheLabelsWithBars(): void
    {
        $tickets = $this->tickets();

        // The field's spelling of a suggestion, as for any record written.
        self::assertSame(['Urgent', 'client x'], $tickets['T-1']->values['labels']);
        self::assertNull($tickets['T-2']->values['labels']);
    }

    public function testAnUpgradeComparesTheLabelsAsASet(): void
    {
        // The same labels, in another order and another case: nothing to do.
        $report = $this->upgrade('1.1.0', [['code' => 'T-1', 'labels' => ['client x', 'urgent'], 'country' => 'Italy', 'callback' => '09:30'], ['code' => 'T-2', 'country' => 'gb']]);
        self::assertSame([], $report->applied);

        $report = $this->upgrade('1.2.0', [['code' => 'T-1', 'labels' => ['later'], 'country' => 'Italy', 'callback' => '09:30'], ['code' => 'T-2', 'country' => 'gb']]);
        self::assertSame(['seeds/ticket.yaml: update record T-1'], array_map(strval(...), $report->applied));
        self::assertSame(['Later'], $this->tickets()['T-1']->values['labels']);
    }

    public function testTheExportWritesTheLabelsSeparatedByBars(): void
    {
        $exported = self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand('acme/helpdesk', '1.1.0', ['ticket'], license: 'MIT', seeds: ['ticket' => 'code']));
        $seed = array_values(array_filter($exported->documents, static fn (Document $document): bool => 'seeds/ticket.yaml' === $document->path))[0];
        $records = array_column(self::getContainer()->get(SeedRecords::class)->of($exported, $seed), null, 'code');

        self::assertSame('Urgent|client x', $records['T-1']['labels']);
        self::assertNull($records['T-2']['labels']);

        // A label holding the separator would read back as two.
        self::getContainer()->get(RecordManagerInterface::class)->update($this->tickets()['T-2']->id, new RecordInput('ticket', ['labels' => ['a|b']]));
        $this->expectException(InvalidExport::class);
        $this->expectExceptionMessage('holds the label "a|b" in "labels"');
        self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand('acme/helpdesk', '1.1.0', ['ticket'], license: 'MIT', seeds: ['ticket' => 'code']));
    }

    private static function package(): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read(self::DIRECTORY);
    }

    /**
     * @param list<array<string, mixed>> $records the records of the seed, inline
     */
    private function upgrade(string $version, array $records): \App\Module\Package\Application\Upgrade\UpgradeReport
    {
        $fixture = self::package();
        $documents = array_map(static fn (Document $document): Document => 'seeds/ticket.yaml' === $document->path
            ? new Document($document->kind, $document->id, ['object' => 'acme.ticket', 'key' => 'code', 'mode' => 'managed', 'records' => $records], $document->path)
            : $document, $fixture->documents);
        $manifest = new Document($fixture->manifest->kind, $fixture->manifest->id, ['version' => $version] + $fixture->manifest->spec, $fixture->manifest->path);

        return self::getContainer()->get(PackageUpgrader::class)->upgrade(new Package($manifest, $documents, $fixture->directory), self::DIRECTORY);
    }

    /**
     * @return array<string, RecordOutput> by code
     */
    private function tickets(): array
    {
        $tickets = [];
        foreach (self::getContainer()->get(RecordFinderInterface::class)->each('ticket') as $record) {
            $code = $record->values['code'] ?? null;
            self::assertIsString($code);
            $tickets[$code] = $record;
        }

        return $tickets;
    }
}
