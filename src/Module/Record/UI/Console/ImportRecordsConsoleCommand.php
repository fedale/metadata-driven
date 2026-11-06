<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Record\Application\Import\RecordImporter;
use App\Module\Record\Domain\Exception\ImportRefused;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'record:import', description: 'Import the rows of a CSV file into an object, all or nothing')]
final class ImportRecordsConsoleCommand
{
    public function __construct(
        private readonly RecordImporter $importer,
    ) {
    }

    /**
     * @param list<string> $match
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The object the records belong to')] string $object,
        #[Argument('The CSV file: a header of field names, then one record per line')] string $file,
        #[Option('A unique field: a row whose value a record holds updates that record')] ?string $key = null,
        #[Option('field:targetField, to match the cells of a relation by a unique field of its target instead of ids (repeatable)')] array $match = [],
        #[Option('Go through the whole import, then roll it back: nothing is changed')] bool $dryRun = false,
    ): int {
        $matches = [];
        foreach ($match as $option) {
            [$field, $targetField] = array_pad(explode(':', $option, 2), 2, '');
            $matches[$field] = $targetField;
        }
        try {
            $report = $this->importer->import($object, $file, $key, $matches, $dryRun);
        } catch (ImportRefused $e) {
            $io->error(\sprintf('Nothing was imported: %d problem(s).', \count($e->reasons)));
            $io->listing($e->reasons);

            return Command::FAILURE;
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%s%d record(s) created, %d updated.',
            $report->dryRun ? 'Dry run, nothing was changed: ' : '',
            $report->created,
            $report->updated,
        ));

        return Command::SUCCESS;
    }
}
