<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * The plans of config/packages/plans.yaml, and the one each workspace is on.
 */
interface Plans
{
    /**
     * @return array<string, Plan> by name, in the order configured
     */
    public function all(): array;

    public function get(string $name): ?Plan;

    /**
     * The plan a workspace is on: its own, else the default one (also for a plan no longer
     * configured).
     */
    public function of(Workspace $workspace): Plan;
}
