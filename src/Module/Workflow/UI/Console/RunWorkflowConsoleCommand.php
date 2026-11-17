<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Console;

use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\ManualRunRefused;
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Runs a manual workflow of the command's workspace, as no one: its steps that write need an
 * act_as, as for any event no one is behind. Its permission is not checked: the console is the
 * system administrator's.
 */
#[AsCommand(name: 'workflow:run', description: 'Run a manual workflow, on a record or none, with the answers of its form')]
final class RunWorkflowConsoleCommand
{
    public function __construct(
        private readonly ManualRuns $runs,
        private readonly WorkflowRepository $workflows,
    ) {
    }

    /**
     * @param list<string> $record
     * @param list<string> $input
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workflow: its name or its id')] string $workflow,
        #[Option('The id of the record it runs on; repeatable for a workflow running on a selection')] array $record = [],
        #[Option('An answer of its form, as name=value (repeatable)')] array $input = [],
    ): int {
        $found = Uuid::isValid($workflow) ? $this->workflows->find(Uuid::fromString($workflow)) : $this->workflows->findByName($workflow);
        $answers = [];
        foreach ($input as $pair) {
            if (!str_contains($pair, '=')) {
                $io->error(\sprintf('"%s": an answer is name=value.', $pair));

                return Command::INVALID;
            }
            [$name, $value] = explode('=', $pair, 2);
            $answers[$name] = $value;
        }
        try {
            if (null === $found) {
                throw new ManualRunRefused(\sprintf('There is no workflow "%s".', $workflow), ManualRunRefused::NOT_FOUND);
            }
            $active = $this->runs->get($found->getId()->toRfc4122());
            if (null === $active->definition->trigger->selection && \count($record) > 1) {
                throw new ManualRunRefused(\sprintf('The workflow "%s" runs on one record at a time.', $active->name), ManualRunRefused::INVALID);
            }
            $runs = null === $active->definition->trigger->selection || [] === $record
                ? [$this->runs->run($active, $record[0] ?? null, $answers, asSomeone: false)]
                : $this->runs->runOnSelection($active, $record, $answers, asSomeone: false);
        } catch (ManualRunRefused $e) {
            $io->error([$e->getMessage(), ...array_map(static fn (string $name, string $error): string => $name.': '.$error, array_keys($e->errors), $e->errors)]);

            return Command::FAILURE;
        }
        $failed = 0;
        foreach ($runs as $run) {
            if (RunLog::COMPLETED === $run->status) {
                $io->success(\sprintf('Run %s completed: %d step(s).', $run->id, \count($run->steps)));
            } elseif (RunLog::WAITING === $run->status) {
                // Stopped at a wait: the worker goes on with it, what it did so far is kept.
                $io->success(\sprintf('Run %s waits until %s (UTC): %d step(s) so far.', $run->id, $run->resumeAt?->format('Y-m-d H:i') ?? '?', \count($run->steps)));
            } else {
                ++$failed;
                $io->error(\sprintf('Run %s failed, nothing was kept: %s', $run->id, $run->error));
            }
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
