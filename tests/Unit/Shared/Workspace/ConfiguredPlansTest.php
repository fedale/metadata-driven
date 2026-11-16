<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Workspace;

use App\Shared\Infrastructure\Workspace\ConfiguredPlans;
use App\Shared\Workspace\Workspace;
use PHPUnit\Framework\TestCase;

final class ConfiguredPlansTest extends TestCase
{
    private const array PLANS = ['starter' => ['label' => 'Starter', 'workflow_steps_per_month' => 5000], 'unlimited' => ['label' => 'Unlimited', 'workflow_steps_per_month' => null]];

    public function testAWorkspaceIsOnItsPlanElseTheDefaultOne(): void
    {
        $plans = new ConfiguredPlans(self::PLANS, 'unlimited');

        self::assertSame(['starter', 'unlimited'], array_keys($plans->all()));
        self::assertSame(5000, $plans->of(self::workspace('starter'))->workflowStepsPerMonth);
        self::assertSame('unlimited', $plans->of(self::workspace(null))->name);
        // A plan no longer configured: the default one.
        self::assertSame('unlimited', $plans->of(self::workspace('gold'))->name);
    }

    public function testAMalformedConfigurationIsTold(): void
    {
        $this->expectExceptionMessage('app.default_plan: "gold" is none of app.plans.');
        (new ConfiguredPlans(self::PLANS, 'gold'))->all();
    }

    public function testALimitIsANumberOrNone(): void
    {
        $this->expectExceptionMessage('app.plans: "starter" is no plan');
        (new ConfiguredPlans(['starter' => ['label' => 'Starter', 'workflow_steps_per_month' => 'many']], 'starter'))->all();
    }

    private static function workspace(?string $plan): Workspace
    {
        return new Workspace('0193a0c4-0000-7000-8000-000000000001', 'acme', 'Acme', 1, 'acme_data', 'acme_rbac', true, $plan);
    }
}
