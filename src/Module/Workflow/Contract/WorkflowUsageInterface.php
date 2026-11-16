<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use App\Module\Workflow\Contract\Dto\WorkflowUsage;

/**
 * The current workspace's workflow steps this month, against its plan.
 */
interface WorkflowUsageInterface
{
    /**
     * @throws \LogicException no workspace is selected
     */
    public function thisMonth(): WorkflowUsage;
}
