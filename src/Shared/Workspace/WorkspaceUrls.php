<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * The absolute addresses of the workspaces and of the system console, for what is read away from
 * the request: an email. A workspace answers on <slug>.<base host> (the default workspace on the
 * base host itself), the console on <console label>.<base host>.
 */
interface WorkspaceUrls
{
    /**
     * @param string $path an absolute path, "/" included: the router's ABSOLUTE_PATH
     */
    public function ofWorkspace(Workspace $workspace, string $path = '/'): string;

    /**
     * The current workspace's.
     *
     * @throws \LogicException no workspace is selected
     */
    public function ofCurrent(string $path = '/'): string;

    public function ofConsole(string $path = '/'): string;
}
