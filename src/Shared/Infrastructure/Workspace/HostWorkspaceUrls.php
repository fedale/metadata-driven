<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use App\Shared\Workspace\WorkspaceUrls;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The scheme and the port come from the request when there is one (the host it came on tells
 * how the installation is reached), from DEFAULT_URI in a command or in the worker: the request
 * is read per call, never kept (the service lives across requests in a worker).
 */
final class HostWorkspaceUrls implements WorkspaceUrls
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly Workspaces $workspaces,
        private readonly RequestStack $requests,
        #[Autowire('%app.workspace.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%app.workspace.default%')]
        private readonly string $default,
        #[Autowire('%app.workspace.console_label%')]
        private readonly string $consoleLabel,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function ofWorkspace(Workspace $workspace, string $path = '/'): string
    {
        return $this->url($workspace->slug === $this->default ? $this->baseHost : $workspace->slug.'.'.$this->baseHost, $path);
    }

    public function ofCurrent(string $path = '/'): string
    {
        $workspace = $this->workspaces->byId($this->current->id()) ?? throw new \LogicException('The current workspace is gone.');

        return $this->ofWorkspace($workspace, $path);
    }

    public function ofConsole(string $path = '/'): string
    {
        return $this->url($this->consoleLabel.'.'.$this->baseHost, $path);
    }

    private function url(string $host, string $path): string
    {
        $request = $this->requests->getCurrentRequest();
        if (null !== $request) {
            $scheme = $request->getScheme();
            $port = $request->getPort();
        } else {
            $scheme = parse_url($this->defaultUri, \PHP_URL_SCHEME) ?: 'http';
            $port = parse_url($this->defaultUri, \PHP_URL_PORT);
        }
        $port = null === $port || \in_array((int) $port, [80, 443], true) ? '' : ':'.$port;

        return \sprintf('%s://%s%s/%s', $scheme, $host, $port, ltrim($path, '/'));
    }
}
