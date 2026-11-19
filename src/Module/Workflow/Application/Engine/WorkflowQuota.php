<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Workflow\Contract\Dto\WorkflowUsage;
use App\Module\Workflow\Contract\WorkflowUsageInterface;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Plans;
use App\Shared\Workspace\Workspaces;

/**
 * The workflow steps a workspace's plan allows a calendar month (config/packages/plans.yaml):
 * past them, no run starts until the next month. A run that started finishes, whatever it spends.
 */
final class WorkflowQuota implements WorkflowUsageInterface
{
    public function __construct(
        private readonly RunStore $runs,
        private readonly Plans $plans,
        private readonly Workspaces $workspaces,
        private readonly CurrentWorkspace $workspace,
    ) {
    }

    public function thisMonth(): WorkflowUsage
    {
        $workspace = $this->workspaces->byId($this->workspace->id()) ?? throw new \LogicException('The current workspace is not in the registry.');
        $plan = $this->plans->of($workspace);

        return new WorkflowUsage(
            $plan->label,
            null === $plan->workflowStepsPerMonth ? 0 : $this->runs->stepsThisMonth(),
            $plan->workflowStepsPerMonth,
            $this->runs->emailsThisMonth(),
            $plan->emailsPerMonth,
        );
    }

    /**
     * The emails the current workspace's workflows may still send this month; null for no limit,
     * as outside a workspace (the system admin's workflows).
     */
    public function emailsLeft(): ?int
    {
        return $this->workspace->isSelected() ? $this->thisMonth()->emailsLeft() : null;
    }

    /**
     * Why no run may start, if none may: the month's steps spent. None outside a workspace (the
     * system admin's workflows).
     */
    public function refusal(): ?string
    {
        if (!$this->workspace->isSelected()) {
            return null;
        }
        $usage = $this->thisMonth();

        return $usage->exhausted()
            ? \sprintf('This workspace ran the %d workflow steps its plan (%s) allows a month: no run starts until next month.', $usage->limit, $usage->plan)
            : null;
    }
}
