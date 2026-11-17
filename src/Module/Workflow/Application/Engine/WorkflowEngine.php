<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\FieldType\Contract\Dto\StatusMachine;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Contract\Exception\PackageInstallFailed;
use App\Module\Package\Contract\PackageInstallationInterface;
use App\Module\Record\Contract\Dto\RecordFilter;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Event\RecordTransition;
use App\Module\Record\Contract\Event\RecordWriteAction;
use App\Module\Record\Contract\Event\RecordWriting;
use App\Module\Record\Contract\Event\RecordWritten;
use App\Module\Record\Contract\Exception\InvalidRecordSearch;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\Exception\TransitionRefused;
use App\Module\Record\Contract\RecordEventScopeInterface;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Record\Contract\RecordTransitionsInterface;
use App\Module\Workflow\Application\Definition\Definition;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\FormInput;
use App\Module\Workflow\Application\Definition\Step;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\Definition\Timing;
use App\Module\Workflow\Application\Definition\TriggerEvent;
use App\Module\Workflow\Application\Engine\Message\ResumeRun;
use App\Module\Workflow\Application\Engine\Message\RunScheduled;
use App\Module\Workflow\Application\Engine\Message\RunWebhook;
use App\Module\Workflow\Application\Engine\Message\RunWorkflow;
use App\Module\Workflow\Application\Secrets\SecretsKeyMissing;
use App\Module\Workflow\Application\Secrets\WorkflowSecrets;
use App\Shared\Transaction\TransactionRunner;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use App\Shared\Workspace\WorkspaceSessions;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
        #[Autowire('%app.workflow.max_wait_days%')]
        private readonly int $maxWaitDays,
        private readonly WorkspaceSessions $sessions,
        private readonly PackageInstallationInterface $packages,
        private readonly MessageBusInterface $bus,
        private readonly WorkflowHttp $http,
        private readonly RecordTransitionsInterface $transitions,
        private readonly WorkflowSecrets $secrets,
        private readonly WorkflowQuota $quota,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onWriting(RecordWriting $event): void
    {
        $changed = RecordWriteAction::Update === $event->action
            ? array_keys(array_filter($event->values(), static fn (mixed $value, string $field): bool => $value != $event->current?->get($field), \ARRAY_FILTER_USE_BOTH))
            : array_keys($event->values());
        $workflows = $this->triggered($event->objectName, Timing::Before, $event->action, $changed, $event->transition);
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
            $chain = $this->chain($this->triggerData($event->objectName, $event->action, $record, $event->current, $changed, $event->transition?->toArray()));
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
        $workflows = $this->triggered($event->objectName, Timing::After, $event->action, $event->changed, $event->transition);
        if ([] === $workflows || !$this->workspace->isSelected()) {
            return;
        }
        $depth = 1 + ($event->origin->depth ?? 0);
        $record = ['id' => $event->id, ...$this->readable($event->objectName, $event->record->values)];
        $trigger = $this->triggerData($event->objectName, $event->action, $record, $event->before, $event->changed, $event->transition?->toArray());

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
                if (null !== $refusal = $this->quota->refusal()) {
                    // Its month spent: waiting would not bring the steps back.
                    $run->end(RunLog::FAILED, $refusal);
                    $this->store($run);

                    return $run;
                }
                $chain = $this->chain($message->trigger);
                try {
                    // All of the run or nothing.
                    $this->transactions->run(function () use ($workflow, $chain, $run): void {
                        $this->steps($workflow->definition->steps, $chain, $run, null);
                        if (RunLog::COMPLETED !== $run->status && RunLog::WAITING !== $run->status) {
                            // A refuse ends the run without throwing: nothing of the run is kept.
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
     * A scheduled workflow whose time came, from the queue: in its workspace, as no one (its steps
     * act as their roles), if the version that was due is still active; its steps in one
     * transaction, all or nothing. Past runs_per_minute it fails: the next time is the next run.
     */
    public function runScheduled(RunScheduled $message): ?RunLog
    {
        return $this->runUnattended($message->workspaceId, $message->workflowId, $message->versionId, TriggerEvent::Schedule, ['scheduled_at' => $message->scheduledAt]);
    }

    /**
     * A request to a workflow's address, from the queue: as a scheduled run, $trigger its body
     * and its query.
     */
    public function runWebhook(RunWebhook $message): ?RunLog
    {
        return $this->runUnattended($message->workspaceId, $message->workflowId, $message->versionId, TriggerEvent::Webhook, ['body' => $message->body, 'query' => $message->query]);
    }

    /**
     * A run no one is behind (a time come, a request from outside): in its workspace, its steps
     * acting as their roles, if the version that was active is still.
     *
     * @param array<string, mixed> $trigger what $trigger holds
     */
    private function runUnattended(string $workspaceId, string $workflowId, string $versionId, TriggerEvent $on, array $trigger): ?RunLog
    {
        $work = function () use ($workflowId, $versionId, $on, $trigger): ?RunLog {
            $workflow = $this->workflows->byId($workflowId);
            if (null === $workflow || $workflow->versionId !== $versionId) {
                return null;
            }
            [$previous, $this->actingAs] = [$this->actingAs, null];
            try {
                ++$this->pending;
                $run = new RunLog($workflow, '', null, $on->value, $on->value, null, 1, new \DateTimeImmutable());
                if (null !== $refusal = $this->tooMany() ?? $this->quota->refusal()) {
                    $run->end(RunLog::FAILED, $refusal);
                    $this->store($run);

                    return $run;
                }
                $chain = $this->chain($trigger);
                // No one is behind it, whoever is signed in where it runs.
                $chain->set('$user', null);
                try {
                    $this->transactions->run(function () use ($workflow, $chain, $run): void {
                        $this->steps($workflow->definition->steps, $chain, $run, null);
                        if (RunLog::COMPLETED !== $run->status && RunLog::WAITING !== $run->status) {
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
            } finally {
                $this->actingAs = $previous;
            }
        };
        if ($this->workspace->isSelected() && $this->workspace->id() === $workspaceId) {
            return $work();
        }
        $workspace = $this->workspaces->byId($workspaceId);

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
        // A transition's test: the transition named (or any) from the record's state.
        $transition = TriggerEvent::Transitioned === $trigger->on
            ? ['field' => $trigger->statusField, 'name' => $trigger->transition, 'from' => $record->get((string) $trigger->statusField), 'to' => null]
            : null;
        $chain = $this->chain($this->triggerData($trigger->object, $action, ['id' => $recordId, ...$this->readable($trigger->object, $record->values)], RecordWriteAction::Update === $action ? $record : null, array_keys($values), $transition));

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
                if (RunLog::COMPLETED !== $run->status && RunLog::WAITING !== $run->status) {
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
        $broken = null;
        try {
            $this->steps($workflow->definition->steps, $chain, $run, null);
            $run->end($run->status, $run->error);
        } catch (StepFailed $e) {
            $run->end(RunLog::FAILED, $e->getMessage());
        } catch (\Throwable $e) {
            // Not a step's own failure (the database, a bug): the run is kept all the same, for
            // the console's pages to show what the steps before it did, then the error goes on.
            $run->end(RunLog::FAILED, \sprintf('It broke: %s', $e->getMessage()));
            $broken = $e;
        } finally {
            $this->platformWorkspace = null;
            $run->chain = $chain->toArray();
        }
        $this->store($run);
        if (null !== $broken) {
            throw $broken;
        }

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
    private function triggered(string $object, Timing $when, RecordWriteAction $action, array $changed, ?RecordTransition $transition = null): array
    {
        return array_values(array_filter(
            $this->workflows->triggeredOn($object, $when),
            static fn (ActiveWorkflow $workflow): bool => $workflow->definition->trigger->fires($object, $action, $changed, $transition),
        ));
    }

    private function start(ActiveWorkflow $workflow, string $object, ?string $recordId, RecordWriteAction $action, Timing $when, int $depth): RunLog
    {
        ++$this->pending;

        return new RunLog($workflow, $object, $recordId, $action->value, $when->value, $this->who(), $depth, new \DateTimeImmutable());
    }

    /**
     * Never throws: a run is stored once the transaction it ran in ended, and an error here
     * would fail a message whose run is done already. The queue would then run it again, its
     * writes and its requests a second time. A run that cannot be stored is logged instead.
     */
    private function store(RunLog $run): void
    {
        $run->finishedAt ??= new \DateTimeImmutable();
        --$this->pending;
        try {
            $this->runs->save($run);
            if (RunLog::WAITING === $run->status && null !== $run->resumeAt && $this->workspace->isSelected()) {
                // Stored first: the message finds it, even handled at once (the tests' transport).
                $delay = max(0, (int) (($run->resumeAt->format('U.u') - microtime(true)) * 1000));
                $this->bus->dispatch(new ResumeRun($run->id, $this->workspace->id()), [new DelayStamp($delay)]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('The run {run} of the workflow "{name}" ({status}) could not be stored: {message}', ['run' => $run->id, 'name' => $run->workflow->name, 'status' => $run->status, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * A run that waited: the steps after its wait, in its workspace, as whoever triggered it, in
     * a transaction of their own, all or nothing from there. The version it ran no longer active,
     * it is cancelled.
     */
    public function resume(ResumeRun $message): ?RunLog
    {
        $work = function () use ($message): ?RunLog {
            $waiting = $this->runs->waiting($message->runId);
            if (null === $waiting) {
                return null;
            }
            $workflow = $this->workflows->byId($waiting->workflowId);
            $active = null !== $workflow && $workflow->versionId === $waiting->versionId;
            $workflow ??= new ActiveWorkflow($waiting->workflowId, '', $waiting->versionId, null, (new DefinitionParser())->fromArray(['trigger' => ['on' => 'manual', 'object' => $waiting->object, 'label' => '-'], 'steps' => [['key' => 'x', 'type' => 'refuse', 'message' => '-']]]));
            ++$this->pending;
            $run = new RunLog($workflow, $waiting->object, $waiting->recordId, $waiting->action, $waiting->timing, $waiting->triggeredBy, $waiting->depth, $waiting->startedAt, $waiting->id);
            $run->stepOffset = $waiting->stepCount;
            $chain = new DataChain($waiting->chain);
            if (!$active) {
                $run->end(RunLog::CANCELLED, 'The version it ran is no longer active.');
                $run->chain = $chain->toArray();
                $this->store($run);

                return $run;
            }
            if (null !== $refusal = $this->quota->refusal()) {
                $run->end(RunLog::FAILED, $refusal);
                $run->chain = $chain->toArray();
                $this->store($run);

                return $run;
            }
            // A resumed part counts as a run: past runs_per_minute, it waits a minute more.
            if (null !== $this->tooMany() && $message->delays < self::MAX_DELAYS) {
                --$this->pending;
                $this->bus->dispatch($message->delayed(), [new DelayStamp(60_000)]);

                return null;
            }
            [$previous, $this->actingAs] = [$this->actingAs, $waiting->triggeredBy];
            try {
                $now = new \DateTimeImmutable();
                $chain->set('$now', $now->format(\DateTimeInterface::ATOM));
                $chain->set('$today', $now->format('Y-m-d'));
                // Whoever triggered it as they are now: their roles may have changed meanwhile (each
                // step checks its permission against their assignments now, too).
                $chain->set('$user', null === $waiting->triggeredBy ? null : ['email' => $waiting->triggeredBy, 'roles' => $this->roles->rolesOf($waiting->triggeredBy)]);
                try {
                    $this->transactions->run(function () use ($workflow, $waiting, $chain, $run): void {
                        $this->steps(\array_slice($workflow->definition->steps, $waiting->resumeStep), $chain, $run, null);
                        if (RunLog::COMPLETED !== $run->status && RunLog::WAITING !== $run->status) {
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
            } finally {
                $this->actingAs = $previous;
            }
        };
        if ($this->workspace->isSelected() && $this->workspace->id() === $message->workspaceId) {
            return $work();
        }
        $workspace = $this->workspaces->byId($message->workspaceId);

        return null === $workspace ? null : $this->sessions->within($workspace, $work);
    }

    /**
     * Why the run may not start, if it may not: too deep a chain of runs, too many runs, the
     * month's steps of the workspace's plan spent.
     */
    private function overLimits(RunLog $run): ?string
    {
        return $this->tooDeep($run) ?? $this->tooMany() ?? $this->quota->refusal();
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
     * @param array<string, mixed>      $record
     * @param array<string, mixed>|null $transition the transition the write takes, if any
     * @param list<string>              $changed
     *
     * @return array<string, mixed>
     */
    private function triggerData(string $object, RecordWriteAction $action, array $record, ?RecordOutput $before, array $changed, ?array $transition = null): array
    {
        return [
            'object' => $object,
            'action' => $action->value,
            'record' => $record,
            'before' => null === $before ? null : ['id' => $before->id, ...$this->readable($object, $before->values)],
            'changed' => $changed,
            ...(null === $transition ? [] : ['transition' => $transition]),
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
            if (RunLog::COMPLETED !== $run->status || null !== $run->returned) {
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
        if ($run->executed() >= $this->maxExecutedSteps) {
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
                StepType::Wait => $this->wait($step, $chain, $run),
                // A called workflow's, in a write: as in a workflow run before it.
                StepType::HttpRequest => null === $writing ? $this->request($step, $chain) : throw new StepFailed('No call to the outside in a write: no http_request in a workflow called before the write.'),
                StepType::RunWorkflow => $this->call($step, $chain, $run, $writing),
                StepType::Return => $this->giveBack($step, $chain, $run),
                StepType::Transition => $this->takeTransition($step, $chain, $run),
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
            if (RunLog::COMPLETED !== $run->status || null !== $run->returned) {
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
     * Stops the run until a duration passed or a date came: the steps before it are kept, those
     * after it resume then (store() queues them). A date passed already waits not; a test does
     * not wait.
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    private function wait(Step $step, DataChain $chain, RunLog $run): array
    {
        $now = new \DateTimeImmutable();
        $text = '';
        if (null !== $for = $step->string('for')) {
            $until = $now->modify('+'.$for);
            $input = ['for' => $for];
        } else {
            $text = $chain->text($step->string('until'));
            $input = ['until' => $text];
            try {
                $until = '' === trim($text) ? false : new \DateTimeImmutable($text);
            } catch (\Exception) {
                $until = false;
            }
        }
        if (false === $until) {
            throw new StepFailed(\sprintf('"%s" is no date to wait until.', $text));
        }
        if ($until > $now->modify(\sprintf('+%d days', $this->maxWaitDays))) {
            throw new StepFailed(\sprintf('A run waits %d days at most.', $this->maxWaitDays));
        }
        $output = ['until' => $until->format(\DateTimeInterface::ATOM)];
        if ($this->dryRun || $until <= $now) {
            return [$input, $output, true];
        }
        // A wait is one of the definition's first steps (DefinitionParser): it resumes after it.
        $index = array_search($step->key, array_map(static fn (Step $top): string => $top->key, $run->workflow->definition->steps), true);
        $run->wait($until, (false === $index ? \count($run->workflow->definition->steps) : $index) + 1);

        return [$input, $output, false];
    }

    /**
     * Runs a workflow on called inside this run, in its transaction: its steps as whoever
     * triggered this run (or as their roles), one level deeper; its own run logged. What it
     * returns is the step's output. Its refuse refuses the write this run guards, if any; its
     * failure fails the step. Whoever activated this workflow must hold the permission to run it.
     *
     * @return array{array<string, mixed>, array<string, mixed>|null, bool}
     *
     * @throws StepFailed
     */
    private function call(Step $step, DataChain $chain, RunLog $run, ?RecordWriting $writing): array
    {
        $name = (string) $step->string('workflow');
        $called = $this->workflows->byName($name);
        if (null === $called || !$called->definition->trigger->isCalled()) {
            throw new StepFailed(\sprintf('There is no active workflow "%s" on called.', $name));
        }
        $activator = $run->workflow->activatedBy;
        if (null === $activator || !$this->access->userCanGrant($activator, Permissions::workflowRun($called->workflowId))) {
            throw new StepFailed(\sprintf('%s, who activated it, may not run "%s".', $activator ?? 'No one', $name));
        }
        $given = $chain->values($step->input());
        $inputs = $called->definition->trigger->form;
        if ([] !== $unknown = array_diff(array_keys($given), array_map(static fn (FormInput $input): string => $input->name, $inputs))) {
            throw new StepFailed(\sprintf('"%s" takes no input %s.', $name, implode(', ', $unknown)));
        }
        $input = [];
        foreach ($inputs as $formInput) {
            try {
                $input[$formInput->name] = $formInput->answer($given[$formInput->name] ?? null);
            } catch (\InvalidArgumentException $e) {
                throw new StepFailed(\sprintf('The input "%s" of "%s": %s', $formInput->name, $name, $e->getMessage()), 0, $e);
            }
        }
        $sub = new RunLog($called, '', null, TriggerEvent::Called->value, TriggerEvent::Called->value, $run->triggeredBy, $run->depth + 1, new \DateTimeImmutable());
        if (null !== $refusal = $this->tooDeep($sub)) {
            throw new StepFailed($refusal);
        }
        if (!$this->dryRun) {
            // A run of its own: it counts for the limit.
            ++$this->pending;
            if (null !== $refusal = $this->tooMany()) {
                --$this->pending;
                throw new StepFailed($refusal);
            }
        }
        $now = new \DateTimeImmutable();
        $subChain = new DataChain([
            '$trigger' => ['input' => $input],
            '$user' => $chain->get('$user'),
            '$workspace' => $chain->get('$workspace'),
            '$now' => $now->format(\DateTimeInterface::ATOM),
            '$today' => $now->format('Y-m-d'),
            '$last' => null,
        ]);
        try {
            $this->steps($called->definition->steps, $subChain, $sub, $writing);
            $sub->end($sub->status, $sub->error);
        } catch (StepFailed $e) {
            $sub->end(RunLog::FAILED, $e->getMessage());
        } finally {
            $sub->chain = $subChain->toArray();
            if (!$this->dryRun) {
                // Stored as its caller is: once the transaction they ran in ended.
                $this->transactions->whenDone(fn () => $this->store($sub));
            }
        }
        $logged = ['workflow' => $name, 'input' => $input, 'run' => $sub->id];
        if (RunLog::REFUSED === $sub->status) {
            // It guarded the write this run guards: refused, as by a refuse of this run's.
            $run->end(RunLog::REFUSED, $sub->error);

            return [$logged, null, false];
        }
        if (RunLog::COMPLETED !== $sub->status) {
            throw new StepFailed(\sprintf('"%s" failed: %s', $name, $sub->error ?? 'no reason given.'));
        }

        return [$logged, $sub->returned ?? [], true];
    }

    /**
     * A called workflow's end: the values its caller's step outputs.
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     */
    private function giveBack(Step $step, DataChain $chain, RunLog $run): array
    {
        $values = $chain->values($step->values());
        $run->returned = $values;

        return [$values, $values, false];
    }

    /**
     * Takes a transition of a record's Status field: as a write, the update permission (as whoever
     * triggered the event, or as the role it acts as), and one of the transition's roles (held by
     * them, or given by that role). Its update raises its events if the step says so.
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    private function takeTransition(Step $step, DataChain $chain, RunLog $run): array
    {
        $object = (string) $step->string('object');
        $field = (string) $step->string('field');
        $name = (string) $step->string('name');
        $id = $chain->text($step->string('id'));
        if ('' === $id) {
            throw new StepFailed('No record to take the transition on: the id is empty.');
        }
        $this->allow($step, $object, RecordAction::Update, $run);
        $definition = $this->metadata->getObject($object)->fields[$field] ?? null;
        $transition = null === $definition ? null : (StatusMachine::fromSettings($definition->settings)->transitions[$name] ?? null);
        if (null === $transition) {
            throw new StepFailed(\sprintf('The status "%s" of "%s" has no transition "%s".', $field, $object, $name));
        }
        if ([] !== $transition->roles) {
            $role = $step->actAs();
            $holdsOne = fn (?string $email): bool => null !== $email && [] !== array_filter($transition->roles, fn (string $needed): bool => $this->access->userHolds($email, $needed));
            if (null !== $role) {
                // The role stands for the transition's roles (allow() checked its activator may give it).
                if ([] === array_filter($transition->roles, fn (string $needed): bool => $needed === $role || $this->access->roleGives($role, $needed))) {
                    throw new StepFailed(\sprintf('"%s" is taken by %s only: %s is none of them.', $transition->label, implode(', ', $transition->roles), $role));
                }
            } elseif (!$holdsOne($run->triggeredBy)) {
                throw new StepFailed(\sprintf('"%s" is taken by %s only.', $transition->label, implode(', ', $transition->roles)));
            } elseif (!$holdsOne($run->workflow->activatedBy)) {
                // No way to more power: its activator would take it through the users who may.
                throw new StepFailed(\sprintf('%s, who activated it, holds none of %s.', $run->workflow->activatedBy ?? 'No one', implode(', ', $transition->roles)));
            }
        }
        $input = ['object' => $object, 'id' => $id, 'field' => $field, 'name' => $name];
        try {
            $record = $this->recordEvents->asRun($run->id, $run->depth, $step->emitsEvents() && !$this->dryRun, fn () => $this->transitions->apply($object, $id, $field, $name, asRole: true));
        } catch (TransitionRefused|RecordValidationFailed|\DomainException $e) {
            $message = $e instanceof RecordValidationFailed ? implode(' ', array_merge(...array_values($e->errors))) : $e->getMessage();
            throw new StepFailed($message, 0, $e);
        }

        return [$input, ['id' => $record->id, ...$this->readable($object, $record->values)], true];
    }

    /**
     * A request to the outside (WorkflowHttp): its response's body decoded when it is JSON. A
     * status of 400 or more fails the step, unless fail_on_error is false.
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    private function request(Step $step, DataChain $chain): array
    {
        // The secrets on a copy of the chain, for this request only: never in the run's data.
        $secrets = [];
        if (str_contains((string) json_encode($step->options), '$secrets')) {
            try {
                $secrets = array_filter($this->secrets->values(), static fn (string $value): bool => '' !== $value);
            } catch (SecretsKeyMissing|\UnexpectedValueException $e) {
                throw new StepFailed($e->getMessage(), 0, $e);
            }
            $chain = clone $chain;
            $chain->set('$secrets', $secrets);
        }
        $forms = self::secretForms($secrets);
        try {
            [$input, $output] = $this->send($step, $chain);
        } catch (StepFailed $e) {
            if ([] === $forms) {
                throw $e;
            }
            // A network's error tells the address as sent, encoded its own way: of a request that
            // read a secret, only its host. Masked, and not chained: what it wraps holds it.
            $message = $e->getPrevious() instanceof TransportExceptionInterface
                ? \sprintf('The request to %s got no answer.', parse_url($chain->text($step->string('url')), \PHP_URL_HOST) ?: '?')
                : $e->getMessage();
            throw new StepFailed(\is_string($masked = self::maskedValue($message, $forms)) ? $masked : '');
        }

        return [self::masked($input, $forms), self::masked($output, $forms), true];
    }

    /**
     * Each secret as it may be written down: as it is, in an address (encoded either way), in
     * JSON (escaped), in base64 (a header's credentials); the longest first.
     *
     * @param array<string, string> $secrets
     *
     * @return list<string>
     */
    private static function secretForms(array $secrets): array
    {
        $forms = [];
        foreach ($secrets as $secret) {
            $json = json_encode($secret, \JSON_UNESCAPED_UNICODE);
            $forms = [...$forms, $secret, rawurlencode($secret), urlencode($secret), \is_string($json) ? substr($json, 1, -1) : $secret, base64_encode($secret), rtrim(base64_encode($secret), '=')];
        }
        $forms = array_values(array_unique(array_filter($forms, static fn (string $form): bool => '' !== $form)));
        usort($forms, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $forms;
    }

    /**
     * What may hold a secret, each secret's value replaced by "[secret]": the request and its
     * answer are logged with the run.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $secrets each secret's forms (secretForms())
     *
     * @return array<string, mixed>
     */
    private static function masked(array $values, array $secrets): array
    {
        return array_map(static fn (mixed $value): mixed => self::maskedValue($value, $secrets), $values);
    }

    /**
     * @param list<string> $secrets each secret's forms (secretForms())
     */
    private static function maskedValue(mixed $value, array $secrets): mixed
    {
        return match (true) {
            [] === $secrets => $value,
            \is_string($value) => str_replace($secrets, '[secret]', $value),
            \is_array($value) => array_map(static fn (mixed $item): mixed => self::maskedValue($item, $secrets), $value),
            default => $value,
        };
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     *
     * @throws StepFailed
     */
    private function send(Step $step, DataChain $chain): array
    {
        $method = $step->string('method') ?? 'GET';
        $url = $chain->text($step->string('url'));
        $headers = array_map(fn (mixed $value): string => $chain->text(\is_scalar($value) ? (string) $value : ''), \is_array($step->options['headers'] ?? null) ? $step->options['headers'] : []);
        $body = \array_key_exists('body', $step->options) ? $chain->render($step->options['body']) : null;
        $timeout = \is_int($step->options['timeout'] ?? null) ? $step->options['timeout'] : 10;
        $retries = \is_int($step->options['retries'] ?? null) ? $step->options['retries'] : 0;
        $options = ['headers' => $headers, 'timeout' => $timeout, 'max_duration' => $timeout, 'max_redirects' => 3];
        if (\is_array($body)) {
            $options['json'] = $body;
        } elseif (null !== $body) {
            $options['body'] = DataChain::asText($body);
        }
        $input = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->dryRun) {
            // A test rolls back what it wrote, and a request cannot be: shown as it would go
            // (the caller masks its secrets), not sent. The steps after it read no answer.
            return [$input, ['sent' => false, 'status' => null, 'headers' => [], 'body' => null]];
        }

        $response = $this->http->send($method, $url, $options, $retries);
        $status = $response['status'];
        $decoded = str_contains(strtolower($response['type']), 'json') ? json_decode($response['content'], true) : null;
        $output = ['status' => $status, 'headers' => $response['headers'], 'body' => $decoded ?? $response['content']];
        if ($status >= 400 && false !== ($step->options['fail_on_error'] ?? true)) {
            throw new StepFailed(\sprintf('%s %s answered %d.', $method, $url, $status));
        }

        return [$input, $output];
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
