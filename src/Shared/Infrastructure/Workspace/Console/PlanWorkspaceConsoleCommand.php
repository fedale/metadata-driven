<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace\Console;

use App\Shared\Infrastructure\Workspace\WorkspaceProvisioner;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Workspace\Plan;
use App\Shared\Workspace\Plans;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A workspace's plan (config/packages/plans.yaml): shown, or changed.
 */
#[AsCommand(name: 'workspace:plan', description: 'Show a workspace\'s plan, or put it on another one')]
final class PlanWorkspaceConsoleCommand
{
    public function __construct(
        private readonly WorkspaceRegistry $workspaces,
        private readonly WorkspaceProvisioner $provisioner,
        private readonly Plans $plans,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workspace\'s slug')] string $slug,
        #[Argument('The plan to put it on; none to show its plan')] ?string $plan = null,
    ): int {
        $workspace = $this->workspaces->bySlug($slug);
        if (null === $workspace) {
            $io->error(\sprintf('There is no workspace "%s".', $slug));

            return Command::FAILURE;
        }
        if (null === $plan) {
            $current = $this->plans->of($workspace);
            $io->writeln(\sprintf('%s: %s (%s)%s, %s.', $slug, $current->name, $current->label, null === $workspace->plan ? ', the default' : '', self::limit($current)));

            return Command::SUCCESS;
        }
        try {
            $this->provisioner->assignPlan($workspace, $plan);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Workspace "%s" is on the plan %s: %s.', $slug, $plan, self::limit($this->plans->get($plan) ?? throw new \LogicException('Assigned, so configured.'))));

        return Command::SUCCESS;
    }

    private static function limit(Plan $plan): string
    {
        return null === $plan->workflowStepsPerMonth ? 'no limit on workflow steps' : \sprintf('%d workflow steps a month', $plan->workflowStepsPerMonth);
    }
}
