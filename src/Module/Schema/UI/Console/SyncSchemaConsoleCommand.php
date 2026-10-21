<?php

declare(strict_types=1);

namespace App\Module\Schema\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'schema:sync', description: 'Align the record tables with the metadata')]
final class SyncSchemaConsoleCommand
{
    public function __construct(
        private readonly SchemaSynchronizerInterface $synchronizer,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * @param list<string> $objects
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Objects to synchronize (default: all)')] array $objects = [],
        #[Option('Show the SQL without executing it')] bool $dryRun = false,
        #[Option('Also drop or alter columns and indexes that differ from the metadata')] bool $allowDestructive = false,
    ): int {
        $objects = [] !== $objects ? $objects : array_keys($this->metadata->allObjects());
        $status = Command::SUCCESS;

        foreach ($objects as $objectName) {
            try {
                $plan = $dryRun
                    ? $this->synchronizer->plan($objectName, $allowDestructive)
                    : $this->synchronizer->synchronize($objectName, $allowDestructive);
            } catch (MetadataException $e) {
                $io->error($e->getMessage());
                $status = Command::FAILURE;
                continue;
            }

            $io->section(\sprintf('%s (%s)', $plan->objectName, $plan->tableName));
            if ($plan->isEmpty()) {
                $io->text('Up to date.');
            } else {
                $io->listing($plan->statements);
                $io->text($dryRun ? 'Not executed (dry run).' : 'Executed.');
            }
            foreach ($plan->skipped as $skipped) {
                $io->warning($skipped.' Use --allow-destructive to apply it.');
            }
        }

        return $status;
    }
}
