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
     * @param list<string> $input
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workflow: its name or its id')] string $workflow,
        #[Option('The id of the record it runs on')] ?string $record = null,
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
            $run = $this->runs->run($this->runs->get($found->getId()->toRfc4122()), $record, $answers, asSomeone: false);
        } catch (ManualRunRefused $e) {
            $io->error([$e->getMessage(), ...array_map(static fn (string $name, string $error): string => $name.': '.$error, array_keys($e->errors), $e->errors)]);

            return Command::FAILURE;
        }
        if (RunLog::COMPLETED !== $run->status) {
            $io->error(\sprintf('Run %s failed, nothing was kept: %s', $run->id, $run->error));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Run %s completed: %d step(s).', $run->id, \count($run->steps)));

        return Command::SUCCESS;
    }
}
