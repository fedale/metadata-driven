<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Workspace\SystemConsole;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Selects the workspace a request is for, from its host: <slug>.<base host> (acme.example.com,
 * acme.localhost). The base host itself, and any other host, is the default workspace's, when one
 * is configured (app.workspace.default).
 *
 * An unknown or suspended workspace, or no workspace at all, is a 404 before anything else runs:
 * above the firewall (priority 8), so no session or user is loaded for it.
 *
 * The system console, <console label>.<base host>, is no workspace's: its requests run
 * in the system session. Its paths (/console…) answer there only, and the workspaces' paths
 * nowhere else than on a workspace's host.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 40)]
final class HostWorkspaceListener
{
    public function __construct(
        private readonly WorkspaceRegistry $registry,
        private readonly WorkspaceSwitcher $switcher,
        #[Autowire('%app.workspace.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%app.workspace.default%')]
        private readonly string $default,
        #[Autowire('%app.workspace.console_label%')]
        private readonly string $consoleLabel,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $console = str_starts_with($path, SystemConsole::PATH_PREFIX.'/') || SystemConsole::PATH_PREFIX === $path;

        if ($this->isConsoleHost($request->getHost())) {
            // The system console: the system session, the console's paths and
            // signing in only. Its home is the list of workspaces.
            if ('/' === $path) {
                $event->setResponse(new RedirectResponse(SystemConsole::PATH_PREFIX));

                return;
            }
            // The system admin's own account (global, as every account) answers there too, and so
            // do the pages' styles and scripts: in development AssetMapper serves them through
            // the application, under /assets/ (in production the web server does, from public/).
            if (!$console && !\in_array($path, ['/login', '/logout', '/account'], true) && !str_starts_with($path, '/_') && !str_starts_with($path, '/assets/')) {
                throw new NotFoundHttpException(\sprintf('No page "%s" on the console.', $path));
            }
            $request->attributes->set(SystemConsole::REQUEST_ATTRIBUTE, true);
            $this->switcher->system();

            return;
        }
        if ($console) {
            throw new NotFoundHttpException('The console is on a host of its own.');
        }

        $slug = $this->slug($request->getHost());
        $workspace = null === $slug ? null : $this->registry->bySlug($slug);
        if (null === $workspace || !$workspace->active) {
            throw new NotFoundHttpException(\sprintf('No workspace at "%s".', $event->getRequest()->getHost()));
        }
        $this->switcher->switchTo($workspace);
    }

    private function isConsoleHost(string $host): bool
    {
        return '' !== $this->consoleLabel && '' !== $this->baseHost
            && strtolower($host) === strtolower($this->consoleLabel.'.'.$this->baseHost);
    }

    /**
     * The slug a host names, null for none: a subdomain of the base host must be one label.
     */
    public function slug(string $host): ?string
    {
        $suffix = '.'.strtolower($this->baseHost);
        $host = strtolower($host);
        if ('.' !== $suffix && str_ends_with($host, $suffix)) {
            $label = substr($host, 0, -\strlen($suffix));

            return '' === $label || str_contains($label, '.') ? null : $label;
        }

        return '' === $this->default ? null : $this->default;
    }
}
