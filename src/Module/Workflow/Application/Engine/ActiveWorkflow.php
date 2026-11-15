<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Workflow\Application\Definition\Definition;

/**
 * The active version of a workflow, as the engine runs it.
 */
final readonly class ActiveWorkflow
{
    public function __construct(
        public string $workflowId,
        public string $name,
        public string $versionId,
        /** Who activated it: whose right to give the roles its steps act as is checked at each run. */
        public ?string $activatedBy,
        public Definition $definition,
    ) {
    }
}
