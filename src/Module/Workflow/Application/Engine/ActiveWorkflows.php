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
     * The manual workflows of an object, in the order of the list.
     *
     * @return list<ActiveWorkflow>
     */
    public function manualOn(string $object): array;

    /**
     * A workflow's active version, whatever its trigger; null when it has none.
     */
    public function byId(string $workflowId): ?ActiveWorkflow;

    /**
     * Forgets what was read: a version activated or deactivated.
     */
    public function forget(): void;
}
