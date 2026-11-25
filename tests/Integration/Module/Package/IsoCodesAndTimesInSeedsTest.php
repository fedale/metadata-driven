<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Package\Application\Command\ExportPackageCommand;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Record\Contract\RecordFinderInterface;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentCodec;
use Fedale\PackageFormat\PackageDirectory;
use Fedale\PackageFormat\SeedRecords;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A country and a time in a package's seed (tests/Fixtures/packages/helpdesk): the seed may name
 * the country ("Italy"), the export writes its code; a time reads and writes as 09:30.
 */
final class IsoCodesAndTimesInSeedsTest extends KernelTestCase
{
    private const string DIRECTORY = __DIR__.'/../../../Fixtures/packages/helpdesk';

    public function testASeedMayNameACodeAndTheExportWritesIt(): void
    {
        self::getContainer()->get(PackageInstaller::class)->install((new PackageDirectory(new DocumentCodec()))->read(self::DIRECTORY), self::DIRECTORY);

        $tickets = [];
        foreach (self::getContainer()->get(RecordFinderInterface::class)->each('ticket') as $record) {
            $tickets[$record->get('code')] = $record;
        }
        self::assertSame(['IT', 'GB'], [$tickets['T-1']->get('country'), $tickets['T-2']->get('country')]);
        $callback = $tickets['T-1']->get('callback');
        self::assertInstanceOf(\DateTimeImmutable::class, $callback);
        self::assertSame('09:30', $callback->format('H:i'));

        $exported = self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand('acme/helpdesk', '1.1.0', ['ticket'], license: 'MIT', seeds: ['ticket' => 'code']));
        $seed = array_values(array_filter($exported->documents, static fn (Document $document): bool => 'seeds/ticket.yaml' === $document->path))[0];
        $records = array_column(self::getContainer()->get(SeedRecords::class)->of($exported, $seed), null, 'code');
        self::assertSame(['IT', '09:30'], [$records['T-1']['country'], $records['T-1']['callback']]);
    }
}
