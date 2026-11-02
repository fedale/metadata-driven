<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Forgets an archived object or field for good, its data with it. Asks to type its name back;
 * without a terminal, --force is required.
 */
#[AsCommand(name: 'metadata:purge', description: 'Delete an archived object, or an archived field, for good: its table or column and its data')]
final class PurgeConsoleCommand
{
    public function __construct(
        private readonly MetadataManagerInterface $metadata,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument('Object machine name')] string $object,
        #[Option('A field of the object, to purge an archived field of the object')] ?string $field = null,
        #[Option('Do not ask for confirmation')] bool $force = false,
    ): int {
        $name = null === $field ? $object : $object.'.'.$field;
        $what = null === $field ? \sprintf('archived object "%s", its table and every record', $object) : \sprintf('archived field "%s", its column and every value', $name);
        if (!$force) {
            if (!$input->isInteractive()) {
                $io->error('Purging cannot be undone: pass --force to run it without a terminal.');

                return Command::INVALID;
            }
            $io->warning(\sprintf('This deletes the %s. It cannot be undone.', $what));
            if ($io->ask(\sprintf('Type "%s" to confirm', $name)) !== $name) {
                $io->note('Nothing was deleted.');

                return Command::FAILURE;
            }
        }

        try {
            null === $field ? $this->metadata->purgeObject($object) : $this->metadata->purgeField($object, $field);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Deleted the %s.', $what));

        return Command::SUCCESS;
    }
}
