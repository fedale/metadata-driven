<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * A plan a workspace may be on (config/packages/plans.yaml): what it allows.
 */
final readonly class Plan
{
    public function __construct(
        public string $name,
        public string $label,
        /** The workflow steps a workspace may run in a calendar month; null for no limit. */
        public ?int $workflowStepsPerMonth,
        /** The emails its workflows may send in a calendar month, one per recipient; null for no limit. */
        public ?int $emailsPerMonth = null,
    ) {
    }
}
