<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renames an object, or one of its fields with --field: see
 * MetadataManagerInterface::renameObject() and renameField() for what follows the name.
 */
#[AsCommand(name: 'metadata:rename', description: 'Give an object, or one of its fields, a new machine name')]
final class RenameConsoleCommand
{
    public function __construct(
        private readonly MetadataManagerInterface $metadata,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('The new machine name: of the object, or of the --field')] string $newName,
        #[Option('A field of the object, to rename a field of the object')] ?string $field = null,
    ): int {
        try {
            $rewritten = null === $field
                ? $this->metadata->renameObject($object, $newName)
                : $this->metadata->renameField($object, $field, $newName);
        } catch (MetadataInputInvalid $e) {
            foreach ($e->errors as $input => $messages) {
                $io->error(\sprintf('%s: %s', $input, implode(' ', $messages)));
            }

            return Command::INVALID;
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(null === $field
            ? \sprintf('Object "%s" is now "%s", with its table.', $object, $newName)
            : \sprintf('Field "%s.%s" is now "%s.%s", with its column.', $object, $field, $object, $newName));
        if ([] !== $rewritten) {
            $io->note(['Rewritten to name the new one (a YAML definition loses its comments):', ...$rewritten]);
        }

        return Command::SUCCESS;
    }
}
