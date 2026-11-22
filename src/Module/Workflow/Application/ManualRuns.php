<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordFilter;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The manual workflows: those a user may run (workflow.<id>.run), and their runs, on a record
 * they may read, with the answers of the form checked first.
 */
final class ManualRuns
{
    public function __construct(
        private readonly ActiveWorkflows $workflows,
        private readonly WorkflowEngine $engine,
        private readonly RecordFinderInterface $finder,
        private readonly AccessCheckerInterface $access,
        private readonly MetadataRegistryInterface $metadata,
        #[Autowire('%app.workflow.max_records%')]
        private readonly int $maxRecords,
    ) {
    }

    /**
     * The manual workflows of the object the current user may run on a grid's selection.
     *
     * @return list<ActiveWorkflow>
     */
    public function runnableOnSelection(string $object): array
    {
        return array_values(array_filter($this->runnableOn($object), static fn (ActiveWorkflow $workflow): bool => null !== $workflow->definition->trigger->selection));
    }

    /**
     * The manual workflows of the object the current user may run on one record's page: all but
     * those running on a whole selection at once.
     *
     * @return list<ActiveWorkflow>
     */
    public function runnableOnRecord(string $object): array
    {
        return array_values(array_filter($this->runnableOn($object), static fn (ActiveWorkflow $workflow): bool => !$workflow->definition->trigger->runsOnAll()));
    }

    /**
     * Runs it on a selection's records, its answers checked once: a run per record (each), every
     * one tried whatever the others did, or one run on them all (all). As the current user, who
     * must hold its permission and read the records; or, $asSomeone false, as no one.
     *
     * @param list<string>         $recordIds
     * @param array<string, mixed> $answers
     *
     * @return list<RunLog>
     *
     * @throws ManualRunRefused
     */
    public function runOnSelection(ActiveWorkflow $workflow, array $recordIds, array $answers, bool $asSomeone = true): array
    {
        $trigger = $workflow->definition->trigger;
        if (null === $trigger->selection) {
            throw new ManualRunRefused(\sprintf('The workflow "%s" runs on one record at a time, not on a selection.', $workflow->name), ManualRunRefused::INVALID);
        }
        if ($asSomeone && !$this->mayRun($workflow)) {
            throw new ManualRunRefused(\sprintf('You may not run the workflow "%s".', $workflow->name), ManualRunRefused::FORBIDDEN);
        }
        if ($asSomeone && !$this->access->canRecords($trigger->object, RecordAction::Read)) {
            throw new ManualRunRefused(\sprintf('You may not read the records of "%s".', $trigger->object), ManualRunRefused::FORBIDDEN);
        }
        $records = $this->selection($trigger->object, $recordIds);
        $answers = $this->answers($workflow, $answers);
        if ($trigger->runsOnAll()) {
            return [$this->engine->runManual($workflow, null, $answers, $records)];
        }
        // A run each: refused whole when the minute's runs would end halfway through them (and
        // the workspace's other writes be refused with them, until the minute is over).
        if (\count($records) > $left = $this->engine->runsLeftThisMinute()) {
            throw new ManualRunRefused(\sprintf('%d records selected, a run each: the workspace may start %d more this minute. Select fewer, or run it again in a minute.', \count($records), $left), ManualRunRefused::INVALID);
        }

        return array_map(fn (RecordOutput $record): RunLog => $this->engine->runManual($workflow, $record, $answers), $records);
    }

    /**
     * The records selected, in the order of their ids: one at least, at most app.workflow.max_records.
     *
     * @param list<string> $recordIds
     *
     * @return list<RecordOutput>
     *
     * @throws ManualRunRefused
     */
    private function selection(string $object, array $recordIds): array
    {
        $keyType = $this->metadata->getObject($object)->keyType;
        $canonical = [];
        foreach ($recordIds as $id) {
            // As the database writes them: an id in capitals is the same record.
            $canonical[] = $keyType->normalize($id) ?? throw new ManualRunRefused(\sprintf('"%s" is no record id.', $id), ManualRunRefused::INVALID);
        }
        $recordIds = array_values(array_unique($canonical));
        if ([] === $recordIds) {
            throw new ManualRunRefused('No record selected.', ManualRunRefused::INVALID);
        }
        if (\count($recordIds) > $this->maxRecords) {
            throw new ManualRunRefused(\sprintf('%d records selected: at most %d at a time.', \count($recordIds), $this->maxRecords), ManualRunRefused::INVALID);
        }
        $found = [];
        foreach ($this->finder->search($object, [new RecordFilter('id', 'in', $recordIds)], null, false, $this->maxRecords)->records as $record) {
            $found[$record->id] = $record;
        }
        if (\count($found) !== \count($recordIds)) {
            throw new ManualRunRefused(\sprintf('%d of the records selected are no more: run it again on the others.', \count($recordIds) - \count($found)), ManualRunRefused::NOT_FOUND);
        }

        return array_map(static fn (string $id): RecordOutput => $found[$id], $recordIds);
    }

    /**
     * The manual workflows of the object the current user may run.
     *
     * @return list<ActiveWorkflow>
     */
    public function runnableOn(string $object): array
    {
        return array_values(array_filter($this->workflows->manualOn($object), $this->mayRun(...)));
    }

    /**
     * @throws ManualRunRefused no active manual workflow by that id
     */
    public function get(string $workflowId): ActiveWorkflow
    {
        $workflow = $this->workflows->byId($workflowId);
        if (null === $workflow || !$workflow->definition->trigger->isManual()) {
            throw new ManualRunRefused('There is no active manual workflow by that id.', ManualRunRefused::NOT_FOUND);
        }

        return $workflow;
    }

    public function mayRun(ActiveWorkflow $workflow): bool
    {
        return $this->access->holds(Permissions::workflowRun($workflow->workflowId));
    }

    /**
     * Whether the current user may run it on a selection: its permission, and reading the records
     * (else even how many a grid's filters select would tell them something).
     */
    public function mayRunOnSelection(ActiveWorkflow $workflow): bool
    {
        return $this->mayRun($workflow) && $this->access->canRecords($workflow->definition->trigger->object, RecordAction::Read);
    }

    /**
     * Runs it, its answers checked: as the current user, who must hold its permission and read
     * the record; or, $asSomeone false (a command), as no one, whose writing steps need an act_as.
     *
     * @param array<string, mixed> $answers input name => answer, as sent
     *
     * @throws ManualRunRefused
     */
    public function run(ActiveWorkflow $workflow, ?string $recordId, array $answers, bool $asSomeone = true): RunLog
    {
        if ($workflow->definition->trigger->runsOnAll()) {
            throw new ManualRunRefused(\sprintf('The workflow "%s" runs on a selection of records, all at once.', $workflow->name), ManualRunRefused::INVALID);
        }
        if ($asSomeone && !$this->mayRun($workflow)) {
            throw new ManualRunRefused(\sprintf('You may not run the workflow "%s".', $workflow->name), ManualRunRefused::FORBIDDEN);
        }
        $record = null === $recordId ? null : $this->record($workflow, $recordId, $asSomeone);

        return $this->engine->runManual($workflow, $record, $this->answers($workflow, $answers));
    }

    /**
     * The record it would run on: one of its object, which the current user may read unless
     * $asSomeone is false.
     *
     * @throws ManualRunRefused
     */
    public function record(ActiveWorkflow $workflow, string $recordId, bool $asSomeone = true): RecordOutput
    {
        $object = $workflow->definition->trigger->object;
        if ($asSomeone && !$this->access->canRecords($object, RecordAction::Read)) {
            throw new ManualRunRefused(\sprintf('You may not read the records of "%s".', $object), ManualRunRefused::FORBIDDEN);
        }

        $record = $this->finder->find($object, $recordId);

        return $record
            ?? throw new ManualRunRefused(\sprintf('There is no record %s of "%s".', $recordId, $object), ManualRunRefused::NOT_FOUND);
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed> the answers as the run reads them, every input there
     *
     * @throws ManualRunRefused
     */
    public function answers(ActiveWorkflow $workflow, array $answers): array
    {
        $checked = $errors = [];
        foreach ($workflow->definition->trigger->form as $input) {
            try {
                $checked[$input->name] = $input->answer($answers[$input->name] ?? null);
            } catch (\InvalidArgumentException $e) {
                $errors[$input->name] = $e->getMessage();
            }
        }
        foreach (array_diff(array_keys($answers), array_keys($checked), array_keys($errors)) as $unknown) {
            $errors[(string) $unknown] = 'The form has no such input.';
        }
        if ([] !== $errors) {
            throw new ManualRunRefused('Some answers will not do.', ManualRunRefused::INVALID, $errors);
        }

        return $checked;
    }
}
