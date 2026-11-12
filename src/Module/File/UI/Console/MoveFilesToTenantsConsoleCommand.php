<?php

declare(strict_types=1);

namespace App\Module\File\UI\Console;

use App\Module\File\Application\LegacyFileMoverInterface;
use League\Flysystem\FilesystemException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Once, after upgrading to tenants with directories of their own in the file storage. A tenant
 * command: it runs as the landlord, for every tenant. Running it again does nothing.
 */
#[AsCommand(name: 'tenant:files:move', description: 'Move the files stored before tenants had directories of their own into their tenant\'s directory')]
final class MoveFilesToTenantsConsoleCommand
{
    public function __construct(
        private readonly LegacyFileMoverInterface $mover,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Count what would move and be deleted, change nothing')] bool $dryRun = false,
    ): int {
        try {
            $report = $this->mover->move($dryRun);
        } catch (FilesystemException $e) {
            // The rows moved so far keep their new key; run it again to move the others.
            $io->error(\sprintf('The file storage failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->writeln(\sprintf(
            '%s %d files to their tenant\'s directory, %s %d contents from the root%s.',
            $dryRun ? 'Would move' : 'Moved',
            $report['moved'],
            $dryRun ? 'would delete' : 'deleted',
            $report['deleted'],
            $report['thumbnails'] ? ($dryRun ? ' and its thumbnails' : ' and its thumbnails (made again on demand)') : '',
        ));
        if ([] !== $report['missing']) {
            $io->warning(\sprintf('%d files have no bytes in the storage, left where they are: %s', \count($report['missing']), implode(', ', $report['missing'])));
        }

        return Command::SUCCESS;
    }
}
