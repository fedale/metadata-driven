<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Application\Import\RecordImporter;
use App\Module\Record\Contract\RecordFinderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A CSV import may name a country, a language or a currency as a person writes it, in the
 * current language or in English; a time is read as 14:30.
 */
final class IsoCodesInImportsTest extends KernelTestCase
{
    private string $locale;

    protected function setUp(): void
    {
        $this->locale = \Locale::getDefault();
        \Locale::setDefault('it');
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('supplier', 'Supplier', 'Suppliers');
        $metadata->createField('supplier', 'name', 'text', 'Name', required: true);
        $metadata->createField('supplier', 'country', 'country', 'Country');
        $metadata->createField('supplier', 'language', 'language', 'Language');
        $metadata->createField('supplier', 'currency', 'currency', 'Currency');
        $metadata->createField('supplier', 'opens', 'time', 'Opens');
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->locale);
        parent::tearDown();
    }

    public function testReadsCodesAndNames(): void
    {
        $file = sys_get_temp_dir().'/md-codes-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($file, "name,country,language,currency,opens\nRossi,Italia,italiano,euro,09:30\nSmith,United Kingdom,EN,gbp,\nDupont,fr,,EUR,14:00\n");

        try {
            $report = self::getContainer()->get(RecordImporter::class)->import('supplier', $file, null, []);
        } finally {
            unlink($file);
        }

        self::assertSame(3, $report->created);
        $suppliers = [];
        foreach (self::getContainer()->get(RecordFinderInterface::class)->each('supplier') as $record) {
            $suppliers[$record->get('name')] = [$record->get('country'), $record->get('language'), $record->get('currency')];
        }
        ksort($suppliers);
        self::assertSame(['Dupont' => ['FR', null, 'EUR'], 'Rossi' => ['IT', 'it', 'EUR'], 'Smith' => ['GB', 'en', 'GBP']], $suppliers);
    }
}
