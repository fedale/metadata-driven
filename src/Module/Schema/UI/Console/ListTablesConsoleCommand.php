<?php

declare(strict_types=1);

namespace App\Module\Schema\UI\Console;

use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Module\Schema\Contract\Dto\DatabaseColumn;
use App\Module\Schema\Contract\Dto\DatabaseForeignKey;
use App\Module\Schema\Contract\Dto\DatabaseTable;
use App\Shared\Message\Message;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:table:list', description: 'List the tables of the data schema no object owns, loaded into the database by hand')]
final class ListTablesConsoleCommand
{
    public function __construct(
        private readonly DatabaseTablesInterface $tables,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Show one table in detail: its columns, keys, and whether it can become an object')] ?string $table = null,
    ): int {
        if (null !== $table) {
            $found = $this->tables->find($table);
            if (null === $found) {
                $io->error(\sprintf('The data schema has no table "%s" that no object owns.', $table));

                return Command::FAILURE;
            }
            $this->show($io, $found);

            return Command::SUCCESS;
        }

        $tables = $this->tables->unmanaged();
        if ([] === $tables) {
            $io->success('Every table of the data schema belongs to an object.');

            return Command::SUCCESS;
        }
        $io->table(
            ['Table', 'Columns', 'Rows (estimate)', 'Object name', 'Key', 'Can become an object'],
            array_map(static fn (DatabaseTable $table): array => [
                $table->name,
                \count($table->columns),
                $table->rows ?? '?',
                $table->proposedName ?? '(to choose)',
                $table->keyType->value ?? '-',
                $table->isAdoptable() ? 'yes' : 'no (--table tells why)',
            ], $tables),
        );

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, DatabaseTable $table): void
    {
        $io->title($table->name);
        $io->definitionList(
            ['Kind' => $table->kind->value],
            ['Rows (estimate)' => $table->rows ?? '?'],
            ['Primary key' => [] === $table->primaryKey ? '(none)' : implode(', ', $table->primaryKey)],
            ['Object name' => $table->proposedName ?? '(to choose)'],
            ['Key' => $table->keyType->value ?? '-'],
        );
        $io->table(
            ['Column', 'Type', 'Null', 'Default'],
            array_map(static fn (DatabaseColumn $column): array => [$column->name, $column->type, $column->nullable ? 'yes' : 'no', $column->identity ? '(identity)' : ($column->default ?? '')], $table->columns),
        );
        foreach ($table->uniques as $unique) {
            $io->writeln(\sprintf('Unique: %s', implode(', ', $unique)));
        }
        foreach ($table->foreignKeys as $key) {
            $io->writeln(self::foreignKey($key));
        }
        if ($table->isAdoptable()) {
            $io->success('It can become an object.');

            return;
        }
        $io->warning(['It cannot become an object:', ...array_map(static fn (Message $reason): string => (string) $reason, $table->refusals)]);
    }

    private static function foreignKey(DatabaseForeignKey $key): string
    {
        return \sprintf('Foreign key %s: (%s) → %s.%s (%s), on delete %s', $key->name, implode(', ', $key->columns), $key->referencedSchema, $key->referencedTable, implode(', ', $key->referencedColumns), $key->onDelete);
    }
}
