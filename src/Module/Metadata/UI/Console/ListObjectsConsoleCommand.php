<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:list', description: 'List object definitions, or the fields of one object')]
final class ListObjectsConsoleCommand
{
    public function __construct(
        private readonly MetadataRegistryInterface $registry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Show the fields of this object')] ?string $object = null,
    ): int {
        if (null === $object) {
            $rows = [];
            foreach ($this->registry->allObjects() as $definition) {
                $rows[] = [$definition->name, $definition->labelPlural, $definition->tableName, \count($definition->fields)];
            }
            $io->table(['Name', 'Label', 'Table', 'Fields'], $rows);
            $archived = $this->registry->archivedObjects();
            if ([] !== $archived) {
                $io->section('Archived (metadata:archive --restore, or metadata:purge)');
                $io->table(['Name', 'Label', 'Table'], array_map(static fn ($definition): array => [$definition->name, $definition->labelPlural, $definition->tableName], array_values($archived)));
            }

            return Command::SUCCESS;
        }

        try {
            $definition = $this->registry->getObject($object);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($definition->fields as $field) {
            $rows[] = [
                $field->name,
                $field->type,
                $field->label,
                $field->isRequired ? 'yes' : 'no',
                $field->isUnique ? 'yes' : 'no',
                json_encode($field->settings, \JSON_THROW_ON_ERROR),
            ];
        }
        $io->title(\sprintf('%s (%s)', $definition->labelPlural, $definition->tableName));
        $io->table(['Name', 'Type', 'Label', 'Required', 'Unique', 'Settings'], $rows);
        if ([] !== $definition->archivedFields) {
            $io->section('Archived fields (metadata:archive --restore, or metadata:purge)');
            $io->listing(array_keys($definition->archivedFields));
        }

        return Command::SUCCESS;
    }
}
