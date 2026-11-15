<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Workflow\Application\Definition\Timing;

/**
 * The active workflows of the current workspace, by the object and the moment of their trigger,
 * in the order of the list.
 */
interface ActiveWorkflows
{
    /**
     * @return list<ActiveWorkflow>
     */
    public function triggeredOn(string $object, Timing $when): array;

    /**
     * Forgets what was read: a version activated or deactivated.
     */
    public function forget(): void;
}
