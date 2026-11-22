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

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The table, in the data schema')] string $table,
        #[Option('The object\'s machine name (default: the one proposed from the table\'s)')] ?string $name = null,
        #[Option('Singular label')] ?string $singular = null,
        #[Option('Plural label')] ?string $plural = null,
        #[Option('Show what it would do, change nothing')] bool $dryRun = false,
    ): int {
        try {
            $proposal = $this->adopter->propose($table);
            $proposed = $proposal->request();
            $request = new AdoptionRequest(
                $table,
                $name ?? $proposed->objectName,
                $singular ?? $proposed->labelSingular,
                $plural ?? $proposed->labelPlural,
                $proposed->columns,
            );
            $io->table(
                ['Column', 'Type', 'Field', 'Field type'],
                array_map(static fn (ColumnProposal $column): array => [
                    $column->column->name,
                    $column->column->type,
                    $column->mapping()->fieldName ?? '(left out)',
                    $column->mapping()->type ?? '',
                ], array_values($proposal->columns)),
            );
            $report = $this->adopter->adopt($request, $dryRun);
        } catch (AdoptionRefused $e) {
            $io->error(['The table was not adopted:', ...array_map(static fn (Message $reason): string => (string) $reason, $e->reasons)]);

            return Command::FAILURE;
        }

        $io->listing($report->statements);
        $io->success($dryRun
            ? \sprintf('Dry run: object "%s" would be made of table "%s". Nothing was changed.', $report->objectName, $table)
            : \sprintf('Table "%s" is object "%s" now.', $table, $report->objectName));

        return Command::SUCCESS;
    }
}
