<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Contract\Exception\PackageInstallFailed;
use App\Module\Package\Contract\PackageInstallationInterface;
use App\Module\Record\Contract\Dto\RecordFilter;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Event\RecordWriteAction;
use App\Module\Record\Contract\Event\RecordWriting;
use App\Module\Record\Contract\Event\RecordWritten;
use App\Module\Record\Contract\Exception\InvalidRecordSearch;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordEventScopeInterface;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Workflow\Application\Definition\Definition;
use App\Module\Workflow\Application\Definition\Step;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\Definition\Timing;
use App\Module\Workflow\Application\Definition\TriggerEvent;
use App\Module\Workflow\Application\Engine\Message\RunWorkflow;
use App\Shared\Transaction\TransactionRunner;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use App\Shared\Workspace\WorkspaceSessions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runs the workflows a record's write triggers.
 *
 * - **Before** the write, in its transaction: each workflow in the list's order; set_values
 *   changes the values the write validates, refuse refuses it, and so does a step that fails.
 * - **After** the write committed: each workflow's steps in one transaction of their own, all or
 *   nothing; a failure fails the run, never the write.
 *
 * Every step that writes checks the permission it needs: as whoever triggered the event, or as
 * the role it acts as, which whoever activated the workflow must still be able to give. Its
 * writes raise no event unless it says so, and a chain of runs stops at app.workflow.max_depth.
 * Each run is stored once the transaction it ran in ended, committed or rolled back.
 */
final class WorkflowEngine implements ResetInterface
{
    /** Runs started in this process and not stored yet: they count for the limit too. */
    private int $pending = 0;

    /** A test is running: its writes raise no event, whatever its steps say. */
    private bool $dryRun = false;

    /** A system workflow is running: the workspace its platform steps work in. */
    private ?Workspace $platformWorkspace = null;

    /** A queued run is running: whoever triggered it (no one is signed in in the worker). */
    private ?string $actingAs = null;

    /** How many times a run goes back to the queue, a minute each, past runs_per_minute. */
    private const int MAX_DELAYS = 10;

    public function __construct(
        private readonly ActiveWorkflows $workflows,
        private readonly RunStore $runs,
        private readonly ConditionEvaluator $conditions,
        private readonly RecordManagerInterface $records,
        private readonly RecordFinderInterface $finder,
        private readonly RecordEventScopeInterface $recordEvents,
        private readonly AccessCheckerInterface $access,
        private readonly WorkspaceRolesInterface $roles,
        private readonly MetadataRegistryInterface $metadata,
        private readonly TransactionRunner $transactions,
        private readonly Security $security,
        private readonly CurrentWorkspace $workspace,
        private readonly Workspaces $workspaces,
        #[Autowire('%app.workflow.max_depth%')]
        private readonly int $maxDepth,
        #[Autowire('%app.workflow.runs_per_minute%')]
        private readonly int $runsPerMinute,
        #[Autowire('%app.workflow.max_executed_steps%')]
        private readonly int $maxExecutedSteps,
        #[Autowire('%app.workflow.max_records%')]
        private readonly int $maxRecords,
        private readonly WorkspaceSessions $sessions,
        private readonly PackageInstallationInterface $packages,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener]
    public function onWriting(RecordWriting $event): void
    {
        $changed = RecordWriteAction::Update === $event->action
            ? array_keys(array_filter($event->values(), static fn (mixed $value, string $field): bool => $value != $event->current?->get($field), \ARRAY_FILTER_USE_BOTH))
            : array_keys($event->values());
        $workflows = $this->triggered($event->objectName, Timing::Before, $event->action, $changed);
        if ([] === $workflows) {
            return;
        }
        $depth = 1 + ($event->origin->depth ?? 0);
        $record = ['id' => $event->id, ...$this->readable($event->objectName, null === $event->current ? [] : $event->current->values), ...$this->readable($event->objectName, $event->values())];

        foreach ($workflows as $workflow) {
            $run = $this->start($workflow, $event->objectName, $event->id, $event->action, Timing::Before, $depth);
            $this->transactions->whenDone(fn () => $this->store($run));
            if (null !== $refusal = $this->overLimits($run)) {
                $run->end(RunLog::REFUSED, $refusal);
                $event->refuse($refusal);

                return;
            }
            $chain = $this->chain($this->triggerData($event->objectName, $event->action, $record, $event->current, $changed));
            try {
                $this->steps($workflow->definition->steps, $chain, $run, $event);
                $run->end($run->status, $run->error);
            } catch (StepFailed $e) {
                $run->end(RunLog::REFUSED, $e->getMessage());
                // A workflow run before the write guards it: what could not run refuses it.
                $event->refuse(\sprintf('The workflow "%s" could not run: %s', $workflow->name, $e->getMessage()));
            } finally {
                $run->chain = $chain->toArray();
            }
            if (RunLog::REFUSED === $run->status) {
                if (null === $event->refusal()) {
                    $event->refuse((string) $run->error);
                }

                return;
            }
        }
    }

    #[AsEventListener]
    public function onWritten(RecordWritten $event): void
    {
        $workflows = $this->triggered($event->objectName, Timing::After, $event->action, $event->changed);
        if ([] === $workflows || !$this->workspace->isSelected()) {
            return;
        }
        $depth = 1 + ($event->origin->depth ?? 0);
        $record = ['id' => $event->id, ...$this->readable($event->objectName, $event->record->values)];
        $trigger = $this->triggerData($event->objectName, $event->action, $record, $event->before, $event->changed);

        // The write committed already (RecordWritten): its runs go to the worker.
        foreach ($workflows as $workflow) {
            $this->bus->dispatch(new RunWorkflow($this->workspace->id(), $workflow->workflowId, $workflow->versionId, $event->objectName, $event->id, $event->action->value, $trigger, $this->who(), $depth));
        }
    }

    /**
     * An after-run, from the queue: in its workspace, as whoever triggered it, if the version
     * that was active is still. Its steps in one transaction, all or nothing; past runs_per_minute,
     * back to the queue a minute later (MAX_DELAYS times, then failed).
     */
    public function runQueued(RunWorkflow $message): ?RunLog
    {
        $work = function () use ($message): ?RunLog {
            $workflow = $this->workflows->byId($message->workflowId);
            if (null === $workflow || $workflow->versionId !== $message->versionId) {
                // Deactivated, or another version since: it runs no more.
                return null;
            }
            [$previous, $this->actingAs] = [$this->actingAs, $message->triggeredBy];
            try {
                $run = new RunLog($workflow, $message->object, $message->recordId, $message->action, Timing::After->value, $message->triggeredBy, $message->depth, new \DateTimeImmutable());
                ++$this->pending;
                if (null !== $refusal = $this->tooDeep($run)) {
                    $run->end(RunLog::FAILED, $refusal);
                    $this->store($run);

                    return $run;
                }
                if (null !== $refusal = $this->tooMany()) {
                    if ($message->delays < self::MAX_DELAYS) {
                        --$this->pending;
                        $this->bus->dispatch($message->delayed(), [new DelayStamp(60_000)]);

                        return null;
                    }
                    $run->end(RunLog::FAILED, $refusal);
                    $this->store($run);

                    return $run;
                }
                $chain = $this->chain($message->trigger);
                try {
                    // All of the run or nothing.
                    $this->transactions->run(fn () => $this->steps($workflow->definition->steps, $chain, $run, null));
                    $run->end($run->status, $run->error);
                } catch (StepFailed $e) {
                    $run->end(RunLog::FAILED, $e->getMessage());
                } finally {
                    $run->chain = $chain->toArray();
                }
                $this->transactions->whenDone(fn () => $this->store($run));

                return $run;
            } finally {
                $this->actingAs = $previous;
            }
        };
        if ($this->workspace->isSelected() && $this->workspace->id() === $message->workspaceId) {
            return $work();
        }
        $workspace = $this->workspaces->byId($message->workspaceId);

        // A workspace deleted meanwhile: nothing to run.
        return null === $workspace ? null : $this->sessions->within($workspace, $work);
    }

    /**
     * Whoever the steps act for: who triggered a queued run, else who is signed in.
     */
    private function who(): ?string
    {
        return $this->actingAs ?? $this->security->getUser()?->getUserIdentifier();
    }

    /**
     * Runs a definition against a record for real, then rolls everything back: what each step
     * would read, write and output, with nothing kept (no record, no run stored), as whoever runs
     * the test. A before-trigger runs as the update of the record with its own values (a create
     * for a trigger on created), an after-trigger as the record written. No event is raised by its
     * writes, and the limits don't apply.
     *
     * @throws \InvalidArgumentException no such record
     */
    public function dryRun(Definition $definition, string $name, string $recordId): RunLog
    {
        $trigger = $definition->trigger;
        $record = $this->finder->find($trigger->object, $recordId) ?? throw new \InvalidArgumentException(\sprintf('There is no record %s of "%s".', $recordId, $trigger->object));
        if ($trigger->isManual()) {
            return $this->dryRunManual($definition, $name, $record);
        }
        $action = match ($trigger->on) {
            TriggerEvent::Created => RecordWriteAction::Create,
            TriggerEvent::Deleted => RecordWriteAction::Delete,
            default => RecordWriteAction::Update,
        };
        $workflow = new ActiveWorkflow('', $name, '', $this->security->getUser()?->getUserIdentifier(), $definition);
        $run = new RunLog($workflow, $trigger->object, $recordId, $action->value, $trigger->when->value, $workflow->activatedBy, 1, new \DateTimeImmutable());
        $values = RecordWriteAction::Delete === $action ? [] : $this->readable($trigger->object, $record->values);
        $chain = $this->chain($this->triggerData($trigger->object, $action, ['id' => $recordId, ...$this->readable($trigger->object, $record->values)], RecordWriteAction::Update === $action ? $record : null, array_keys($values)));

        $this->dryRun = true;
        try {
            $this->transactions->run(function () use ($definition, $trigger, $action, $recordId, $values, $record, $chain, $run): never {
                $writing = Timing::Before === $trigger->when
                    ? new RecordWriting($trigger->object, $action, RecordWriteAction::Create === $action ? null : $recordId, $values, RecordWriteAction::Create === $action ? null : $record, null)
                    : null;
                try {
                    $this->steps($definition->steps, $chain, $run, $writing);
                    $run->end($run->status, $run->error);
                } catch (StepFailed $e) {
                    $run->end(Timing::Before === $trigger->when ? RunLog::REFUSED : RunLog::FAILED, $e->getMessage());
                }

                throw new DryRunRolledBack();
            });
        } catch (DryRunRolledBack) {
        } finally {
            $this->dryRun = false;
            $run->chain = $chain->toArray();
        }

        return $run;
    }

    /**
     * Runs a manual workflow, as whoever is signed in (no one from a command), on a record or
     * none, or on a selection's records for a trigger running on them all, with the answers of
     * its form, checked already: its steps in one transaction, all or nothing (a refuse
     * included). Who may run it is the caller's to check.
     *
     * @param array<string, mixed> $input
     * @param list<RecordOutput>   $records a selection's, for a trigger running on them all
     */
    public function runManual(ActiveWorkflow $workflow, ?RecordOutput $record, array $input, array $records = []): RunLog
    {
        $trigger = $workflow->definition->trigger;
        ++$this->pending;
        $run = new RunLog($workflow, $trigger->object, $record?->id, TriggerEvent::Manual->value, TriggerEvent::Manual->value, $this->who(), 1, new \DateTimeImmutable());
        $readable = fn (RecordOutput $one): array => ['id' => $one->id, ...$this->readable($trigger->object, $one->values)];
        if (null !== $refusal = $this->overLimits($run)) {
            $run->end(RunLog::FAILED, $refusal);
            $this->store($run);

            return $run;
        }
        $chain = $this->chain([
            'object' => $trigger->object,
            'action' => TriggerEvent::Manual->value,
            ...($trigger->runsOnAll()
                ? ['records' => array_map($readable, $records)]
                : ['record' => null === $record ? null : $readable($record)]),
            'input' => $input,
        ]);
        try {
            $this->transactions->run(function () use ($workflow, $chain, $run): void {
                $this->steps($workflow->definition->steps, $chain, $run, null);
                if (RunLog::COMPLETED !== $run->status) {
                    // A refuse: nothing of the run is kept.
                    throw new StepFailed((string) $run->error);
                }
            });
            $run->end($run->status, $run->error);
        } catch (StepFailed $e) {
            $run->end(RunLog::FAILED, $e->getMessage());
        } finally {
            $run->chain = $chain->toArray();
        }
        $this->transactions->whenDone(fn () => $this->store($run));

        return $run;
    }

    /**
     * A manual workflow's test: run on the record with its form unanswered (each input null,
     * false for a boolean), then rolled back.
     */
    private function dryRunManual(Definition $definition, string $name, RecordOutput $record): RunLog
    {
        $trigger = $definition->trigger;
        $workflow = new ActiveWorkflow('', $name, '', $this->security->getUser()?->getUserIdentifier(), $definition);
        $run = new RunLog($workflow, $trigger->object, $record->id, TriggerEvent::Manual->value, TriggerEvent::Manual->value, $workflow->activatedBy, 1, new \DateTimeImmutable());
        $input = [];
        foreach ($trigger->form as $formInput) {
            $input[$formInput->name] = 'boolean' === $formInput->type ? false : null;
        }
        $chain = $this->chain(['object' => $trigger->object, 'action' => TriggerEvent::Manual->value, 'record' => ['id' => $record->id, ...$this->readable($trigger->object, $record->values)], 'input' => $input]);

        $this->dryRun = true;
        try {
            $this->transactions->run(function () use ($definition, $chain, $run): never {
                try {
                    $this->steps($definition->steps, $chain, $run, null);
                    $run->end($run->status, $run->error);
                } catch (StepFailed $e) {
                    $run->end(RunLog::FAILED, $e->getMessage());
                }

                throw new DryRunRolledBack();
            });
        } catch (DryRunRolledBack) {
        } finally {
            $this->dryRun = false;
            $run->chain = $chain->toArray();
        }

        return $run;
    }

    /**
     * Runs a system workflow on a platform event, as the system: in the system session, its
     * platform steps entering the workspace concerned for what they do there, each all or nothing
     * on its own. A failed step fails the run; what the steps before it did stays. The run is the
     * system's (no workspace).
     *
     * @param array<string, mixed> $trigger what $trigger holds: workspace, member
     */
    public function runPlatform(ActiveWorkflow $workflow, Workspace $workspace, array $trigger): RunLog
    {
        ++$this->pending;
        $run = new RunLog($workflow, '', null, $workflow->definition->trigger->on->value, 'platform', null, 1, new \DateTimeImmutable());
        $chain = $this->chain($trigger, ['slug' => $workspace->slug, 'name' => $workspace->name]);
        $this->platformWorkspace = $workspace;
        try {
            $this->steps($workflow->definition->steps, $chain, $run, null);
            $run->end($run->status, $run->error);
        } catch (StepFailed $e) {
            $run->end(RunLog::FAILED, $e->getMessage());
        } finally {
            $this->platformWorkspace = null;
            $run->chain = $chain->toArray();
        }
        $this->store($run);

        return $run;
    }

    public function reset(): void
    {
        $this->pending = 0;
        $this->dryRun = false;
        $this->platformWorkspace = null;
        $this->actingAs = null;
    }

    /**
     * @param list<string> $changed
     *
     * @return list<ActiveWorkflow>
     */
    private function triggered(string $object, Timing $when, RecordWriteAction $action, array $changed): array
    {
        return array_values(array_filter(
            $this->workflows->triggeredOn($object, $when),
            static fn (ActiveWorkflow $workflow): bool => $workflow->definition->trigger->fires($object, $action, $changed),
        ));
    }

    private function start(ActiveWorkflow $workflow, string $object, ?string $recordId, RecordWriteAction $action, Timing $when, int $depth): RunLog
    {
        ++$this->pending;

        return new RunLog($workflow, $object, $recordId, $action->value, $when->value, $this->who(), $depth, new \DateTimeImmutable());
    }

    private function store(RunLog $run): void
    {
        $run->finishedAt ??= new \DateTimeImmutable();
        $this->runs->save($run);
        --$this->pending;
    }

    /**
     * Why the run may not start, if it may not: too deep a chain of runs, too many runs.
     */
    private function overLimits(RunLog $run): ?string
    {
        return $this->tooDeep($run) ?? $this->tooMany();
    }

    private function tooDeep(RunLog $run): ?string
    {
        return $run->depth > $this->maxDepth
            ? \sprintf('The workflow "%s" was started by a chain of %d workflows: at most %d.', $run->workflow->name, $run->depth, $this->maxDepth)
            : null;
    }

    private function tooMany(): ?string
    {
        return $this->runs->countSince(new \DateTimeImmutable('-1 minute')) + $this->pending > $this->runsPerMinute
            ? \sprintf('Too many workflow runs in this workspace: at most %d a minute.', $this->runsPerMinute)
            : null;
    }

    /**
     * What $trigger holds for a record's write.
     *
     * @param array<string, mixed> $record
     * @param list<string>         $changed
     *
     * @return array<string, mixed>
     */
    private function triggerData(string $object, RecordWriteAction $action, array $record, ?RecordOutput $before, array $changed): array
    {
        return [
            'object' => $object,
            'action' => $action->value,
            'record' => $record,
            'before' => null === $before ? null : ['id' => $before->id, ...$this->readable($object, $before->values)],
            'changed' => $changed,
        ];
    }

    /**
     * @param array<string, mixed>                   $trigger   what $trigger holds
     * @param array{slug: string, name: string}|null $workspace a system workflow's: the one concerned,
     *                                                          and no $user (no one is behind the platform)
     */
    private function chain(array $trigger, ?array $workspace = null): DataChain
    {
        $user = null === $workspace ? $this->who() : null;
        if (null === $workspace && $this->workspace->isSelected() && null !== $current = $this->workspaces->byId($this->workspace->id())) {
            $workspace = ['slug' => $current->slug, 'name' => $current->name];
        }
        $now = new \DateTimeImmutable();

        return new DataChain([
            '$trigger' => $trigger,
            '$user' => null === $user ? null : ['email' => $user, 'roles' => $this->roles->rolesOf($user)],
            '$workspace' => $workspace,
            '$now' => $now->format(\DateTimeInterface::ATOM),
            '$today' => $now->format('Y-m-d'),
            '$last' => null,
        ]);
    }

    /**
     * A record's values as the chain shows them: never a password, even hashed (the chain is
     * stored with the run).
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function readable(string $object, array $values): array
    {
        $fields = $this->metadata->getObject($object)->fields;

        return array_filter($values, static fn (string $field): bool => 'password' !== ($fields[$field] ?? null)?->type, \ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param list<Step> $steps
     *
     * @return bool false when a filter stopped the run
     *
     * @throws StepFailed
     */
    private function steps(array $steps, DataChain $chain, RunLog $run, ?RecordWriting $writing): bool
    {
        foreach ($steps as $step) {
            if (!$this->step($step, $chain, $run, $writing)) {
                return false;
            }
            if (RunLog::COMPLETED !== $run->status) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool false when the run stops here (a filter that does not hold)
     *
     * @throws StepFailed
     */
    private function step(Step $step, DataChain $chain, RunLog $run, ?RecordWriting $writing): bool
    {
        if (\count($run->steps) >= $this->maxExecutedSteps) {
            throw new StepFailed(\sprintf('More than %d steps executed in one run: stopped.', $this->maxExecutedSteps));
        }
        $started = hrtime(true);
        if (StepType::If === $step->type) {
            // Logged before the steps of its branch, which are logged as they run.
            $branch = $this->branch($step, $chain);
            $output = ['branch' => $branch];
            $run->step($step->key, $step->type->value, 'completed', null, $output, null, self::since($started));
            $chain->set($step->key, $output);
            $chain->set('$last', $output);

            return null === $branch || $this->steps($step->branches[$branch]->steps, $chain, $run, $writing);
        }
        if (StepType::Iterator === $step->type) {
            return $this->iterate($step, $chain, $run, $writing, $started);
        }
        $input = null;
        try {
            [$input, $output, $goOn] = match ($step->type) {
                StepType::Filter => [null, ['passed' => $passed = null !== $step->when && $this->conditions->holds($step->when, $chain)], $passed],
                StepType::SetValues => $this->setValues($step, $chain, $writing, $run),
                StepType::Refuse => $this->refuse($step, $chain, $run, null !== $writing),
                StepType::SearchRecords => $this->search($step, $chain, $run),
                StepType::InstallPackage, StepType::AssignRole => $this->platform($step, $chain),
                default => $this->write($step, $chain, $run),
            };
        } catch (StepFailed $e) {
            $run->step($step->key, $step->type->value, 'failed', $chain->render($step->options), null, $e->getMessage(), self::since($started));

            throw $e;
        }
        $run->step($step->key, $step->type->value, 'completed', $input, $output, null, self::since($started));
        $chain->set($step->key, $output);
        $chain->set('$last', $output);

        return $goOn;
    }

    /**
     * Runs its steps once per item, the item and its index under its key. A filter that stops
     * skips the item; a refuse, or a failure, ends the run. Logged before the steps it runs.
     *
     * @return bool false when the run ends here (a refuse)
     *
     * @throws StepFailed
     */
    private function iterate(Step $step, DataChain $chain, RunLog $run, ?RecordWriting $writing, int|float $started): bool
    {
        $items = $chain->render((string) $step->string('items'));
        if (!\is_array($items) || !array_is_list($items) || \count($items) > $this->maxRecords) {
            $error = \is_array($items) && array_is_list($items)
                ? \sprintf('%d items: at most %d.', \count($items), $this->maxRecords)
                : \sprintf('%s is no list: nothing to run on.', $step->string('items'));
            $run->step($step->key, $step->type->value, 'failed', ['items' => $items], null, $error, self::since($started));

            throw new StepFailed($error);
        }
        $run->step($step->key, $step->type->value, 'completed', ['items' => \count($items)], ['count' => \count($items)], null, self::since($started));
        foreach ($items as $index => $item) {
            $chain->set($step->key, ['item' => $item, 'index' => $index]);
            $this->steps($step->steps, $chain, $run, $writing);
            if (RunLog::COMPLETED !== $run->status) {
                return false;
            }
            // A filter that stopped skips this item only.
        }
        $chain->set($step->key, ['count' => \count($items)]);
        $chain->set('$last', ['count' => \count($items)]);

        return true;
    }

    /**
     * The records an object holds that every test holds for, the first ones in its order.
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    private function search(Step $step, DataChain $chain, RunLog $run): array
    {
        $object = (string) $step->string('object');
        $this->allow($step, $object, RecordAction::Read, $run);
        $filters = array_map(
            static fn (array $test): RecordFilter => new RecordFilter($test['field'], $test['op'], \array_key_exists('value', $test) ? $chain->render($test['value']) : null),
            $step->where(),
        );
        $limit = $step->options['limit'] ?? $this->maxRecords;
        $limit = min(\is_int($limit) ? $limit : $this->maxRecords, $this->maxRecords);
        $input = [
            'object' => $object,
            'where' => array_map(static fn (RecordFilter $filter): array => ['field' => $filter->field, 'op' => $filter->operator, 'value' => $filter->value], $filters),
            'sort' => $step->string('sort'),
            'direction' => $step->string('direction') ?? 'asc',
            'limit' => $limit,
        ];
        try {
            $result = $this->finder->search($object, $filters, $step->string('sort'), 'desc' === $step->string('direction'), $limit);
        } catch (InvalidRecordSearch $e) {
            throw new StepFailed($e->getMessage(), 0, $e);
        }
        $records = array_map(fn (RecordOutput $record): array => ['id' => $record->id, ...$this->readable($object, $record->values)], $result->records);

        return [$input, ['records' => $records, 'count' => \count($records), 'total' => $result->total, 'first' => $records[0] ?? null], true];
    }

    /**
     * install_package, assign_role: in the workspace a system workflow runs for, as the system.
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    private function platform(Step $step, DataChain $chain): array
    {
        $workspace = $this->platformWorkspace ?? throw new StepFailed(\sprintf('%s runs in the system admin\'s workflows only.', $step->type->value));
        if (StepType::InstallPackage === $step->type) {
            [$package, $repo] = [(string) $step->string('package'), $step->string('repo')];
            try {
                $installed = $this->sessions->within($workspace, fn (): array => $this->packages->install($package, $repo));
            } catch (PackageInstallFailed $e) {
                throw new StepFailed($e->getMessage(), 0, $e);
            }

            return [['package' => $package, 'repo' => $repo], ['installed' => $installed], true];
        }
        $email = mb_strtolower(trim($chain->text($step->string('email'))));
        $role = (string) $step->string('role');
        if ('' === $email) {
            throw new StepFailed('No one to give the role to: the email is empty.');
        }
        try {
            $given = $this->sessions->within($workspace, function () use ($email, $role): bool {
                $held = $this->roles->rolesOf($email);
                if (\in_array($role, $held, true)) {
                    return false;
                }
                $this->roles->setRoles($email, [...$held, $role]);

                return true;
            });
        } catch (\InvalidArgumentException $e) {
            throw new StepFailed(\sprintf('%s: %s', $workspace->slug, $e->getMessage()), 0, $e);
        }

        return [['email' => $email, 'role' => $role], ['given' => $given], true];
    }

    /**
     * The branch whose condition holds first, the else if none does; null when there is no else.
     */
    private function branch(Step $step, DataChain $chain): ?int
    {
        foreach ($step->branches as $i => $branch) {
            if (null === $branch->when || $this->conditions->holds($branch->when, $chain)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    private function setValues(Step $step, DataChain $chain, ?RecordWriting $writing, RunLog $run): array
    {
        if (null === $writing || RecordWriteAction::Delete === $writing->action) {
            throw new StepFailed('set_values runs before a create or an update only.');
        }
        // It changes what someone else writes: whoever activated the workflow must still hold
        // that write too (the writer's own permission is checked where they write).
        $permission = Permissions::record($writing->objectName, RecordWriteAction::Create === $writing->action ? RecordAction::Create : RecordAction::Update);
        $activator = $run->workflow->activatedBy;
        if (null === $activator || !$this->access->userCanGrant($activator, $permission)) {
            throw new StepFailed(\sprintf('%s, who activated it, no longer holds %s.', $activator ?? 'No one', $permission));
        }
        $values = $chain->values($step->values());
        foreach ($values as $field => $value) {
            $writing->setValue($field, $value);
        }

        return [$values, $values, true];
    }

    /**
     * Before a write, refuses it; after one, ends the run as failed.
     *
     * @return array{array{message: string}, null, bool}
     */
    private function refuse(Step $step, DataChain $chain, RunLog $run, bool $before): array
    {
        $message = $chain->text($step->string('message'));
        $run->end($before ? RunLog::REFUSED : RunLog::FAILED, $message);

        return [['message' => $message], null, false];
    }

    /**
     * create_record, update_record, delete_record, upsert_record.
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    private function write(Step $step, DataChain $chain, RunLog $run): array
    {
        $object = (string) $step->string('object');
        $values = $chain->values($step->values());
        $id = null === $step->string('id') ? null : $chain->text($step->string('id'));
        if (StepType::UpdateRecord === $step->type || StepType::DeleteRecord === $step->type) {
            if (null === $id || '' === $id) {
                throw new StepFailed(\sprintf('No record to %s: the id is empty.', StepType::UpdateRecord === $step->type ? 'update' : 'delete'));
            }
        }
        if (StepType::UpsertRecord === $step->type) {
            $match = (string) $step->string('match');
            $matched = \is_scalar($values[$match] ?? null) ? $this->finder->findBy($object, $match, (string) $values[$match]) : null;
            $id = $matched?->id;
        }
        $action = match ($step->type) {
            StepType::CreateRecord => RecordAction::Create,
            StepType::DeleteRecord => RecordAction::Delete,
            StepType::UpsertRecord => null === $id ? RecordAction::Create : RecordAction::Update,
            default => RecordAction::Update,
        };
        $this->allow($step, $object, $action, $run);
        $input = ['object' => $object, 'id' => $id, 'values' => $values];

        try {
            $output = $this->recordEvents->asRun($run->id, $run->depth, $step->emitsEvents() && !$this->dryRun, function () use ($step, $object, $values, $id, $action): array {
                if (RecordAction::Delete === $action) {
                    $this->records->delete($object, (string) $id);

                    return ['id' => $id];
                }
                $record = RecordAction::Create === $action
                    ? $this->records->create(new RecordInput($object, $values))
                    : $this->records->update((string) $id, new RecordInput($object, $values));

                return ['id' => $record->id, ...$this->readable($object, $record->values), ...(StepType::UpsertRecord === $step->type ? ['created' => RecordAction::Create === $action] : [])];
            });
        } catch (RecordValidationFailed $e) {
            throw new StepFailed(implode(' ', array_map(static fn (string $field, array $messages): string => ('' === $field ? '' : $field.': ').implode(' ', $messages), array_keys($e->errors), $e->errors)), 0, $e);
        } catch (\DomainException $e) {
            // Not found, a refused delete, an unknown object.
            throw new StepFailed($e->getMessage(), 0, $e);
        }

        return [$input, $output, true];
    }

    /**
     * No way to more power: the step writes as whoever triggered the event, who must hold the
     * permission, and so must whoever activated the workflow, still (else the activator would
     * write through the users who hold it); or as the role it acts as, which the activator must
     * still be able to give.
     *
     * @throws StepFailed
     */
    private function allow(Step $step, string $object, RecordAction $action, RunLog $run): void
    {
        $permission = Permissions::record($object, $action);
        $activator = $run->workflow->activatedBy;
        if (null !== $role = $step->actAs()) {
            if (null === $activator || !$this->access->userCanGrant($activator, $role)) {
                throw new StepFailed(\sprintf('It acts as %s, which %s can no longer give.', $role, $activator ?? 'no one'));
            }
            if (!$this->access->roleGives($role, $permission)) {
                throw new StepFailed(\sprintf('%s does not give %s.', $role, $permission));
            }

            return;
        }
        // Whoever triggered it: in the worker as in a request, from their assignments.
        $triggeredBy = $run->triggeredBy;
        if (null === $triggeredBy) {
            throw new StepFailed('No one triggered this event: choose a role for the step to act as.');
        }
        if (!$this->access->userCanGrant($triggeredBy, $permission)) {
            throw new StepFailed(\sprintf('%s does not hold %s.', $triggeredBy, $permission));
        }
        // For a permission, canGrant is holding it.
        if (null === $activator || !$this->access->userCanGrant($activator, $permission)) {
            throw new StepFailed(\sprintf('%s, who activated it, no longer holds %s.', $activator ?? 'No one', $permission));
        }
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
