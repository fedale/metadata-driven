<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

/**
 * A step could not do what it says: a permission missing, a record refused, nothing to update.
 * It ends the run: before a write, the write is refused; after one, the run fails.
 */
final class StepFailed extends \RuntimeException
{
}
