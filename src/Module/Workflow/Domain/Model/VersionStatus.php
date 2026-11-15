<?php

declare(strict_types=1);

namespace App\Module\Workflow\Domain\Model;

/**
 * Where a version of a workflow stands. Only the active version runs; editing an active workflow
 * makes a draft while it keeps running.
 */
enum VersionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Deactivated = 'deactivated';
    case Archived = 'archived';
}
