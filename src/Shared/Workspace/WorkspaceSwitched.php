<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * The database session now works for another workspace, or for none. Whatever keeps a workspace's
 * data in memory for the rest of a request (a metadata snapshot, translations) forgets it.
 */
final readonly class WorkspaceSwitched
{
    public function __construct(public ?Workspace $workspace)
    {
    }
}
