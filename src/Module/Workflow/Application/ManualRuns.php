<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use Symfony\Component\Uid\Uuid;

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
    ) {
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
     * Runs it, its answers checked: as the current user, who must hold its permission and read
     * the record; or, $asSomeone false (a command), as no one, whose writing steps need an act_as.
     *
     * @param array<string, mixed> $answers input name => answer, as sent
     *
     * @throws ManualRunRefused
     */
    public function run(ActiveWorkflow $workflow, ?string $recordId, array $answers, bool $asSomeone = true): RunLog
    {
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

        $record = Uuid::isValid($recordId) ? $this->finder->find($object, $recordId) : null;

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
