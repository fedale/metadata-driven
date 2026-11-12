<?php

declare(strict_types=1);

namespace App\Module\User\UI\Twig;

use App\Module\User\Domain\Repository\MembershipRepository;
use App\Shared\Tenant\CurrentTenant;
use App\Shared\Tenant\Tenants;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Twig\Attribute\AsTwigFunction;

/**
 * The tenants a user may sign in to, for the tenant switcher of the top bar: each a link to its
 * host, <slug>.<base host>, the default tenant's being the base host itself. Users sign in to
 * each tenant on its own: a session is per host.
 */
final class TenantLinks
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly Tenants $tenants,
        private readonly CurrentTenant $current,
        private readonly RequestStack $requests,
        #[Autowire('%app.tenant.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%app.tenant.default%')]
        private readonly string $default,
    ) {
    }

    /**
     * @return list<array{name: string, url: string, current: bool}> empty unless the user may
     *                                                               sign in to two tenants or more
     */
    #[AsTwigFunction('tenant_links')]
    public function links(string $userId): array
    {
        $request = $this->requests->getCurrentRequest();
        $ids = $this->memberships->tenantsOf(Uuid::fromString($userId));
        if (null === $request || \count($ids) < 2) {
            return [];
        }
        $port = $request->getPort();
        $port = null === $port || \in_array($port, [80, 443], true) ? '' : ':'.$port;
        $current = $this->current->isSelected() ? $this->current->id() : null;

        $links = [];
        foreach ($ids as $id) {
            $tenant = $this->tenants->byId($id);
            if (null === $tenant || !$tenant->active) {
                continue;
            }
            $host = $tenant->slug === $this->default ? $this->baseHost : $tenant->slug.'.'.$this->baseHost;
            $links[] = [
                'name' => $tenant->name,
                'url' => \sprintf('%s://%s%s/', $request->getScheme(), $host, $port),
                'current' => $tenant->id === $current,
            ];
        }
        usort($links, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return \count($links) < 2 ? [] : $links;
    }
}
