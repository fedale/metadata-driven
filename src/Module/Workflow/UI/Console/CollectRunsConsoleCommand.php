<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Console;

use App\Module\Workflow\Application\Engine\RunStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The runs of the workflows hold the data of the records they ran on: kept a while, then
 * deleted. For the command's workspace; every workspace with workspace:run --all.
 */
#[AsCommand(name: 'workflow:runs:gc', description: 'Delete the runs of the workflows older than the days they are kept')]
final class CollectRunsConsoleCommand
{
    public function __construct(
        private readonly RunStore $runs,
        #[Autowire('%app.workflow.runs_keep_days%')]
        private readonly int $keepDays,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Keep the runs of the last days, instead of app.workflow.runs_keep_days')] ?int $days = null,
    ): int {
        $days ??= $this->keepDays;
        if ($days < 1) {
            $io->error('Keep the runs one day at least.');

            return Command::INVALID;
        }
        $deleted = $this->runs->deleteBefore(new \DateTimeImmutable(\sprintf('-%d days', $days)));
        $io->success(\sprintf('%d run(s) older than %d days deleted.', $deleted, $days));

        return Command::SUCCESS;
    }
}
