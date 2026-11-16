<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\Engine\Message\RunScheduled;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use App\Shared\Workspace\WorkspaceSessions;
use Cron\CronExpression;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The workflows on a schedule: every minute, in every active workspace, those whose cron
 * expression is due (UTC) go to the queue, one run each. A minute the scheduler missed (its worker
 * down) runs no workflow later: only the last one missed is checked.
 */
final class ScheduledWorkflows
{
    public function __construct(
        private readonly ActiveWorkflows $workflows,
        private readonly Workspaces $workspaces,
        private readonly WorkspaceSessions $sessions,
        private readonly CurrentWorkspace $current,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @return list<RunScheduled> the runs queued
     */
    public function queueDue(\DateTimeImmutable $now): array
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $minute = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'));
        $queued = [];
        foreach ($this->workspaces->all() as $workspace) {
            if (!$workspace->active) {
                continue;
            }
            $due = fn (): array => array_values(array_filter($this->workflows->scheduled(), static fn (ActiveWorkflow $workflow): bool => null !== $workflow->definition->trigger->cron && (new CronExpression($workflow->definition->trigger->cron))->isDue($minute, 'UTC')));
            foreach ($this->in($workspace, $due) as $workflow) {
                $queued[] = $message = new RunScheduled($workspace->id, $workflow->workflowId, $workflow->versionId, $minute->format(\DateTimeInterface::ATOM));
                $this->bus->dispatch($message);
            }
        }

        return $queued;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function in(Workspace $workspace, callable $work): mixed
    {
        return $this->current->isSelected() && $this->current->id() === $workspace->id ? $work() : $this->sessions->within($workspace, $work);
    }
}
