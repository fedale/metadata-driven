<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Event\RecordWriteAction;
use App\Module\Record\Contract\Event\RecordWriting;
use App\Module\Record\Contract\Event\RecordWritten;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordEventScopeInterface;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Workflow\Application\Definition\Step;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\Definition\Timing;
use App\Shared\Transaction\TransactionRunner;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspaces;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
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
            $chain = $this->chain($event->objectName, $event->action, $record, $event->current, $changed);
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
        $depth = 1 + ($event->origin->depth ?? 0);
        $record = ['id' => $event->id, ...$this->readable($event->objectName, $event->record->values)];

        foreach ($workflows as $workflow) {
            $run = $this->start($workflow, $event->objectName, $event->id, $event->action, Timing::After, $depth);
            if (null !== $refusal = $this->overLimits($run)) {
                $run->end(RunLog::FAILED, $refusal);
                $this->store($run);
                continue;
            }
            $chain = $this->chain($event->objectName, $event->action, $record, $event->before, $event->changed);
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
        }
    }

    public function reset(): void
    {
        $this->pending = 0;
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

        return new RunLog($workflow, $object, $recordId, $action->value, $when->value, $this->security->getUser()?->getUserIdentifier(), $depth, new \DateTimeImmutable());
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
        if ($run->depth > $this->maxDepth) {
            return \sprintf('The workflow "%s" was started by a chain of %d workflows: at most %d.', $run->workflow->name, $run->depth, $this->maxDepth);
        }
        if ($this->runs->countSince(new \DateTimeImmutable('-1 minute')) + $this->pending > $this->runsPerMinute) {
            return \sprintf('Too many workflow runs in this workspace: at most %d a minute.', $this->runsPerMinute);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string>         $changed
     */
    private function chain(string $object, RecordWriteAction $action, array $record, ?RecordOutput $before, array $changed): DataChain
    {
        $user = $this->security->getUser()?->getUserIdentifier();
        $workspace = $this->workspace->isSelected() ? $this->workspaces->byId($this->workspace->id()) : null;
        $now = new \DateTimeImmutable();

        return new DataChain([
            '$trigger' => [
                'object' => $object,
                'action' => $action->value,
                'record' => $record,
                'before' => null === $before ? null : ['id' => $before->id, ...$this->readable($object, $before->values)],
                'changed' => $changed,
            ],
            '$user' => null === $user ? null : ['email' => $user, 'roles' => $this->roles->rolesOf($user)],
            '$workspace' => null === $workspace ? null : ['slug' => $workspace->slug, 'name' => $workspace->name],
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
        $input = null;
        try {
            [$input, $output, $goOn] = match ($step->type) {
                StepType::Filter => [null, ['passed' => $passed = null !== $step->when && $this->conditions->holds($step->when, $chain)], $passed],
                StepType::SetValues => $this->setValues($step, $chain, $writing, $run),
                StepType::Refuse => $this->refuse($step, $chain, $run, null !== $writing),
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
            $output = $this->recordEvents->asRun($run->id, $run->depth, $step->emitsEvents(), function () use ($step, $object, $values, $id, $action): array {
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
        if (null === $this->security->getUser()) {
            throw new StepFailed('No one triggered this event: choose a role for the step to act as.');
        }
        if (!$this->access->canRecords($object, $action)) {
            throw new StepFailed(\sprintf('%s does not hold %s.', $this->security->getUser()->getUserIdentifier(), $permission));
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
