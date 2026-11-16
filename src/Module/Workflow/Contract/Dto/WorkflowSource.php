<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract\Dto;

/**
 * A workflow of the workspace, with its current definition (PackagedWorkflowsInterface::all()).
 */
final readonly class WorkflowSource
{
    /**
     * @param array<string, mixed> $definition as the app stores it; as written when its draft
     *                                         does not parse (an item the user changed, then)
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $definition,
        public bool $active,
    ) {
    }
}
