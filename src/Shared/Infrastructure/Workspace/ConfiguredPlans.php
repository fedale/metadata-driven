<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Workspace\Plan;
use App\Shared\Workspace\Plans;
use App\Shared\Workspace\Workspace;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The plans as config/packages/plans.yaml writes them (app.plans, app.default_plan).
 */
#[AsAlias(Plans::class)]
final class ConfiguredPlans implements Plans
{
    /** @var array<string, Plan>|null */
    private ?array $plans = null;

    /**
     * @param array<mixed> $configured
     */
    public function __construct(
        #[Autowire('%app.plans%')]
        private readonly array $configured,
        #[Autowire('%app.default_plan%')]
        private readonly string $default,
    ) {
    }

    public function all(): array
    {
        if (null !== $this->plans) {
            return $this->plans;
        }
        $plans = [];
        foreach ($this->configured as $name => $plan) {
            $steps = \is_array($plan) ? ($plan['workflow_steps_per_month'] ?? null) : null;
            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $name) || !\is_array($plan) || !\is_string($plan['label'] ?? null)
                || (null !== $steps && (!\is_int($steps) || $steps < 0))) {
                throw new \LogicException(\sprintf('app.plans: "%s" is no plan: a name (lowercase, 50 characters at most), a label, and workflow_steps_per_month, a number or ~.', (string) $name));
            }
            $plans[$name] = new Plan($name, $plan['label'], $steps);
        }
        if (!isset($plans[$this->default])) {
            throw new \LogicException(\sprintf('app.default_plan: "%s" is none of app.plans.', $this->default));
        }

        return $this->plans = $plans;
    }

    public function get(string $name): ?Plan
    {
        return $this->all()[$name] ?? null;
    }

    public function of(Workspace $workspace): Plan
    {
        return (null === $workspace->plan ? null : $this->get($workspace->plan)) ?? $this->all()[$this->default];
    }
}
