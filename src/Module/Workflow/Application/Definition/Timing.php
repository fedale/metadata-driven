<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * When a record trigger runs: before the write, in its transaction (it may change or refuse
 * it), or after the write's transaction committed.
 */
enum Timing: string
{
    case Before = 'before';
    case After = 'after';
}
