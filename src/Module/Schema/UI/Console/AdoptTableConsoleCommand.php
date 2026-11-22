<?php

declare(strict_types=1);

namespace App\Module\Schema\UI\Console;

use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Dto\ColumnProposal;
use App\Module\Schema\Contract\Exception\AdoptionRefused;
use App\Module\Schema\Contract\TableAdopterInterface;
use App\Shared\Message\Message;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:table:adopt', description: 'Make a table loaded into the data schema by hand an object, its records and keys kept')]
final class AdoptTableConsoleCommand
{
    public function __construct(
        private readonly TableAdopterInterface $adopter,
    ) {
    }

    /**
     * @param list<string> $tables
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tables, in the data schema: adopted together, a foreign key between two of them becomes a relation')] array $tables,
        #[Option('The object\'s machine name, for one table (default: the one proposed from the table\'s)')] ?string $name = null,
        #[Option('Singular label, for one table')] ?string $singular = null,
        #[Option('Plural label, for one table')] ?string $plural = null,
        #[Option('Show what it would do, change nothing')] bool $dryRun = false,
    ): int {
        if (\count($tables) > 1 && (null !== $name || null !== $singular || null !== $plural)) {
            $io->error('--name, --singular and --plural name the object of one table: adopt several with the names proposed, or one at a time.');

            return Command::INVALID;
        }
        try {
            $requests = [];
            foreach ($this->adopter->proposeSet($tables) as $proposal) {
                $proposed = $proposal->request();
                $requests[] = new AdoptionRequest(
                    $proposed->table,
                    $name ?? $proposed->objectName,
                    $singular ?? $proposed->labelSingular,
                    $plural ?? $proposed->labelPlural,
                    $proposed->columns,
                );
                $io->section($proposal->table->name);
                $io->table(
                    ['Column', 'Type', 'Field', 'Field type'],
                    array_map(static fn (ColumnProposal $column): array => [
                        $column->column->name,
                        $column->column->type,
                        $column->mapping()->fieldName ?? '(left out)',
                        null === $column->mapping() ? '' : $column->mapping()->type.(null !== $column->mapping()->targetTable ? ' → '.$column->mapping()->targetTable : ''),
                    ], array_values($proposal->columns)),
                );
            }
            $reports = $this->adopter->adoptSet($requests, $dryRun);
        } catch (AdoptionRefused $e) {
            $io->error(['Nothing was adopted:', ...array_map(static fn (Message $reason): string => (string) $reason, $e->reasons)]);

            return Command::FAILURE;
        }

        foreach ($reports as $report) {
            $io->section($report->objectName);
            $io->listing($report->statements);
        }
        $objects = implode(', ', array_map(static fn ($report): string => '"'.$report->objectName.'"', $reports));
        $io->success($dryRun
            ? \sprintf('Dry run: %s would be made of %s. Nothing was changed.', $objects, implode(', ', $tables))
            : \sprintf('%s: %s now.', implode(', ', $tables), $objects));

        return Command::SUCCESS;
    }
}
