<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Archives an object or one of its fields (its data stays), or brings it back with --restore.
 */
#[AsCommand(name: 'metadata:archive', description: 'Take an object, or one of its fields, out of the application, keeping its data; --restore brings it back')]
final class ArchiveConsoleCommand
{
    public function __construct(
        private readonly MetadataManagerInterface $metadata,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Option('A field of the object, to archive a field of the object')] ?string $field = null,
        #[Option('Restore the archived object or field instead')] bool $restore = false,
    ): int {
        $what = null === $field ? \sprintf('Object "%s"', $object) : \sprintf('Field "%s.%s"', $object, $field);
        try {
            match (true) {
                null === $field && !$restore => $this->metadata->archiveObject($object),
                null === $field => $this->metadata->restoreObject($object),
                !$restore => $this->metadata->archiveField($object, $field),
                default => $this->metadata->restoreField($object, $field),
            };
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($restore
            ? \sprintf('%s is back, with its data.', $what)
            : \sprintf('%s is archived: out of the application, its data kept. Restore it with --restore, or purge it with metadata:purge.', $what));

        return Command::SUCCESS;
    }
}
