<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Application\Import\RecordImporter;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Domain\Exception\ImportRefused;
use App\Tests\Support\RemovesDirectories;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Imports CSV files into objects, against the real database.
 */
final class RecordImporterTest extends KernelTestCase
{
    use RemovesDirectories;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-import-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('region', 'Region', 'Regions');
        $metadata->createField('region', 'code', 'text', 'Code', required: true, unique: true);
        $metadata->createField('region', 'name', 'text', 'Name', required: true);
        $metadata->createObject('province', 'Province', 'Provinces');
        $metadata->createField('province', 'code', 'text', 'Code', required: true, unique: true, settings: ['maxLength' => 2]);
        $metadata->createField('province', 'name', 'text', 'Name', required: true);
        $metadata->createField('province', 'region', 'relation', 'Region', required: true, settings: ['target' => 'region']);
        $metadata->createField('province', 'coat', 'file', 'Coat of arms', settings: ['image' => true]);
        $this->import('region', "code,name\n01,Piemonte\n03,Lombardia\n");
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testImportsRowsMatchingRelationsByAFieldOfTheTarget(): void
    {
        $report = $this->import('province', "code,name,region\nTO,Torino,01\nMI,Milano,03\n", key: 'code', matches: ['region' => 'code']);

        self::assertSame([2, 0, false], [$report->created, $report->updated, $report->dryRun]);
        $provinces = $this->byCode('province');
        self::assertSame('Torino', $provinces['TO']->values['name']);
        self::assertSame($this->byCode('region')['01']->id, $provinces['TO']->values['region']);
    }

    public function testAKeyUpdatesTheRecordsItNames(): void
    {
        $this->import('province', "code,name,region\nTO,Torino,01\n", key: 'code', matches: ['region' => 'code']);

        $report = $this->import('province', "code,name,region\nTO,Città metropolitana di Torino,01\nMI,Milano,03\n", key: 'code', matches: ['region' => 'code']);

        self::assertSame([1, 1], [$report->created, $report->updated]);
        self::assertSame('Città metropolitana di Torino', $this->byCode('province')['TO']->values['name']);
        self::assertCount(2, $this->byCode('province'));
    }

    public function testRefusesTheWholeFileWithEveryRowRefused(): void
    {
        try {
            // A code too long, a missing name, a region nobody has: three lines, three reasons.
            $this->import('province', "code,name,region\nTOR,Torino,01\nMI,,03\nRM,Roma,12\nNA,Napoli,03\n", key: 'code', matches: ['region' => 'code']);
            self::fail('The rows refused must refuse the file.');
        } catch (ImportRefused $e) {
            self::assertCount(3, $e->reasons, implode("\n", $e->reasons));
            self::assertStringStartsWith('Line 2: code: ', $e->reasons[0]);
            self::assertSame('Line 3: name: This value is required.', $e->reasons[1]);
            self::assertSame('Line 4: region: no record matches "12".', $e->reasons[2]);
        }
        // Napoli, valid, was not written either.
        self::assertSame([], $this->byCode('province'));
    }

    public function testRefusesUnknownColumnsAndRepeatedKeysBeforeWriting(): void
    {
        try {
            $this->import('province', "code,name,capital\nTO,Torino,yes\nTO,Torino,no\n", key: 'code');
            self::fail('Unknown columns and repeated keys must be refused.');
        } catch (ImportRefused $e) {
            self::assertSame([
                'Column "capital": "province" has no such field.',
                'Line 3: code: line 2 has the same key.',
            ], $e->reasons);
        }
    }

    public function testADryRunChangesNothing(): void
    {
        $report = $this->import('province', "code,name,region\nTO,Torino,01\n", matches: ['region' => 'code'], dryRun: true);

        self::assertSame([1, true], [$report->created, $report->dryRun]);
        self::assertSame([], $this->byCode('province'));
    }

    public function testReadsFilesNextToTheCsvFileOnly(): void
    {
        $image = imagecreatetruecolor(6, 8);
        imagepng($image, $this->directory.'/torino.png');

        $this->import('province', "code,name,region,coat\nTO,Torino,01,torino.png\n", matches: ['region' => 'code']);
        self::assertIsString($this->byCode('province')['TO']->values['coat']);

        try {
            $this->import('province', "code,name,region,coat\nMI,Milano,03,../../etc/hostname\n", matches: ['region' => 'code']);
            self::fail('A file outside the directory of the CSV file must be refused.');
        } catch (ImportRefused $e) {
            self::assertSame(['Line 2: coat: no file "../../etc/hostname" next to the CSV file.'], $e->reasons);
        }
    }

    public function testImportsFromTheConsole(): void
    {
        file_put_contents($this->directory.'/provinces.csv', "code,name,region\nTO,Torino,01\n");
        $command = new CommandTester((new Application(self::bootKernel()))->find('record:import'));

        $command->execute(['object' => 'province', 'file' => $this->directory.'/provinces.csv', '--key' => 'code', '--match' => ['region:code']]);

        $command->assertCommandIsSuccessful();
        self::assertStringContainsString('1 record(s) created, 0 updated.', $command->getDisplay());
    }

    /**
     * @param array<string, string> $matches
     */
    private function import(string $object, string $csv, ?string $key = null, array $matches = [], bool $dryRun = false): \App\Module\Record\Application\Import\ImportReport
    {
        $file = $this->directory.'/'.$object.'.csv';
        file_put_contents($file, $csv);

        return self::getContainer()->get(RecordImporter::class)->import($object, $file, $key, $matches, $dryRun);
    }

    /**
     * @return array<string, RecordOutput>
     */
    private function byCode(string $object): array
    {
        $records = [];
        foreach (self::getContainer()->get(RecordFinderInterface::class)->each($object) as $record) {
            $code = $record->values['code'] ?? null;
            self::assertIsString($code);
            $records[$code] = $record;
        }

        return $records;
    }
}
