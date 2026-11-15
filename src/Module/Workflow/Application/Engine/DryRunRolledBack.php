<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

/**
 * Thrown at the end of a test to roll back what it wrote.
 *
 * @internal used by WorkflowEngine::dryRun()
 */
final class DryRunRolledBack extends \RuntimeException
{
}
