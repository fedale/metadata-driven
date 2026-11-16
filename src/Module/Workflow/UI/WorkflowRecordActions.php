<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI;

use App\Module\Record\Contract\Dto\RecordActionLink;
use App\Module\Record\Contract\RecordActionsInterface;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\UI\Controller\ManualRunController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * On a record's page, the manual workflows of its object the user may run: one with a form opens
 * it, one without is posted at once. On a grid, those running on a selection: a bulk button each.
 */
final class WorkflowRecordActions implements RecordActionsInterface
{
    public function __construct(
        private readonly ManualRuns $runs,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function actionsFor(string $objectName, string $recordId): array
    {
        return array_map(fn (ActiveWorkflow $workflow): RecordActionLink => new RecordActionLink(
            (string) $workflow->definition->trigger->label,
            $this->urls->generate('workflow_run_record', ['workflowId' => $workflow->workflowId, 'recordId' => $recordId]),
            [] === $workflow->definition->trigger->form ? ManualRunController::FORM : null,
        ), $this->runs->runnableOnRecord($objectName));
    }

    public function selectionActionsFor(string $objectName, ?string $gridName): array
    {
        return array_map(fn (ActiveWorkflow $workflow): RecordActionLink => new RecordActionLink(
            (string) $workflow->definition->trigger->label,
            $this->urls->generate('workflow_run_selection', ['workflowId' => $workflow->workflowId, ...(null === $gridName ? [] : ['grid' => $gridName])]),
        ), $this->runs->runnableOnSelection($objectName));
    }
}
