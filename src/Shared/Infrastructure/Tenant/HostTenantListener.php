<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Tenant\OperatorConsole;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Selects the tenant a request is for, from its host: <slug>.<base host> (acme.example.com,
 * acme.localhost). The base host itself, and any other host, is the default tenant's, when one
 * is configured (app.tenant.default).
 *
 * An unknown or suspended tenant, or no tenant at all, is a 404 before anything else runs:
 * above the firewall (priority 8), so no session or user is loaded for it.
 *
 * The console of the installation, <console label>.<base host>, is no tenant's: its requests run
 * in the landlord's session. Its paths (/console…) answer there only, and the tenants' paths
 * nowhere else than on a tenant's host.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 40)]
final class HostTenantListener
{
    public function __construct(
        private readonly TenantRegistry $registry,
        private readonly TenantSwitcher $switcher,
        #[Autowire('%app.tenant.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%app.tenant.default%')]
        private readonly string $default,
        #[Autowire('%app.tenant.console_label%')]
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
        $console = str_starts_with($path, OperatorConsole::PATH_PREFIX.'/') || OperatorConsole::PATH_PREFIX === $path;

        if ($this->isConsoleHost($request->getHost())) {
            // The console of the installation: the landlord's session, the console's paths and
            // signing in only. Its home is the list of tenants.
            if ('/' === $path) {
                $event->setResponse(new RedirectResponse(OperatorConsole::PATH_PREFIX));

                return;
            }
            // The operator's own account (global, as every account) answers there too.
            if (!$console && !\in_array($path, ['/login', '/logout', '/account'], true) && !str_starts_with($path, '/_')) {
                throw new NotFoundHttpException(\sprintf('No page "%s" on the console.', $path));
            }
            $request->attributes->set(OperatorConsole::REQUEST_ATTRIBUTE, true);
            $this->switcher->landlord();

            return;
        }
        if ($console) {
            throw new NotFoundHttpException('The console is on a host of its own.');
        }

        $slug = $this->slug($request->getHost());
        $tenant = null === $slug ? null : $this->registry->bySlug($slug);
        if (null === $tenant || !$tenant->active) {
            throw new NotFoundHttpException(\sprintf('No tenant at "%s".', $event->getRequest()->getHost()));
        }
        $this->switcher->switchTo($tenant);
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
