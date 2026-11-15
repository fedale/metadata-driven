<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * A branch of an if step: its condition (none for the else), and its steps.
 */
final readonly class Branch
{
    /**
     * @param list<Step> $steps
     */
    public function __construct(
        public ?Condition $when,
        public array $steps,
    ) {
    }
}
