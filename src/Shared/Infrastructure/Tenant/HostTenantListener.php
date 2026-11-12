<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
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
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $slug = $this->slug($event->getRequest()->getHost());
        $tenant = null === $slug ? null : $this->registry->bySlug($slug);
        if (null === $tenant || !$tenant->active) {
            throw new NotFoundHttpException(\sprintf('No tenant at "%s".', $event->getRequest()->getHost()));
        }
        $this->switcher->switchTo($tenant);
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
