<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\User\Contract\Event\MemberAdded;
use App\Module\Workflow\Application\Definition\TriggerEvent;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use App\Shared\Workspace\WorkspaceCreated;
use App\Shared\Workspace\Workspaces;
use App\Shared\Workspace\WorkspaceSessions;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The system admin's workflows: the platform's events are kept as they come, and their
 * workflows run once the response is sent (runPending(), on kernel.terminate and
 * console.terminate), in the system session. Switching sessions clears the entity manager: after
 * the response, it disturbs nothing.
 */
final class PlatformWorkflows implements ResetInterface
{
    /** @var list<array{TriggerEvent, string, array<string, mixed>}> event, workspace id, what holds */
    private array $pending = [];

    public function __construct(
        private readonly ActiveWorkflows $workflows,
        private readonly WorkflowEngine $engine,
        private readonly WorkspaceSessions $sessions,
        private readonly Workspaces $workspaces,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onWorkspaceCreated(WorkspaceCreated $event): void
    {
        $workspace = $event->workspace;
        $this->pending[] = [TriggerEvent::WorkspaceCreated, $workspace->id, ['workspace' => ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => $workspace->name]]];
    }

    #[AsEventListener]
    public function onMemberAdded(MemberAdded $event): void
    {
        $this->pending[] = [TriggerEvent::MemberAdded, $event->workspaceId, ['member' => ['email' => $event->email]]];
    }

    /**
     * Runs the workflows of the events kept, and forgets them. A workflow that breaks (not a step
     * failing: the run says so) is logged, and the others run.
     *
     * @return list<RunLog>
     */
    public function runPending(): array
    {
        if ([] === $this->pending) {
            return [];
        }
        [$events, $this->pending] = [$this->pending, []];

        return $this->sessions->asSystem(function () use ($events): array {
            $runs = [];
            foreach ($events as [$on, $workspaceId, $trigger]) {
                $workspace = $this->workspaces->byId($workspaceId);
                if (null === $workspace) {
                    continue;
                }
                $trigger['workspace'] = ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => $workspace->name];
                foreach ($this->workflows->onPlatform($on) as $workflow) {
                    try {
                        $runs[] = $this->engine->runPlatform($workflow, $workspace, $trigger);
                    } catch (\Throwable $e) {
                        $this->logger->error('The system workflow "{name}" broke on {event} of {slug}: {message}', ['name' => $workflow->name, 'event' => $on->value, 'slug' => $workspace->slug, 'message' => $e->getMessage(), 'exception' => $e]);
                    }
                }
            }

            return $runs;
        });
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
