<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * A workspace is ready: its schemas, its permissions, its first admin (invited or added) and the
 * package asked for are there. Raised by the system console's page and workspace:create, once.
 */
final readonly class WorkspaceCreated
{
    public function __construct(public Workspace $workspace)
    {
    }
}
