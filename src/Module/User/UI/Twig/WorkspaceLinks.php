<?php

declare(strict_types=1);

namespace App\Module\User\UI\Twig;

use App\Module\User\Domain\Repository\MembershipRepository;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspaces;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Twig\Attribute\AsTwigFunction;

/**
 * The workspaces a user may sign in to, for the workspace switcher of the top bar: each a link to its
 * host, <slug>.<base host>, the default workspace's being the base host itself. Users sign in to
 * each workspace on its own: a session is per host.
 */
final class WorkspaceLinks
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly Workspaces $workspaces,
        private readonly CurrentWorkspace $current,
        private readonly RequestStack $requests,
        #[Autowire('%app.workspace.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%app.workspace.default%')]
        private readonly string $default,
    ) {
    }

    /**
     * @return list<array{name: string, url: string, current: bool}> empty unless the user may
     *                                                               sign in to two workspaces or more
     */
    #[AsTwigFunction('workspace_links')]
    public function links(string $userId): array
    {
        $request = $this->requests->getCurrentRequest();
        $ids = $this->memberships->workspacesOf(Uuid::fromString($userId));
        if (null === $request || \count($ids) < 2) {
            return [];
        }
        $port = $request->getPort();
        $port = null === $port || \in_array($port, [80, 443], true) ? '' : ':'.$port;
        $current = $this->current->isSelected() ? $this->current->id() : null;

        $links = [];
        foreach ($ids as $id) {
            $workspace = $this->workspaces->byId($id);
            if (null === $workspace || !$workspace->active) {
                continue;
            }
            $host = $workspace->slug === $this->default ? $this->baseHost : $workspace->slug.'.'.$this->baseHost;
            $links[] = [
                'name' => $workspace->name,
                'url' => \sprintf('%s://%s%s/', $request->getScheme(), $host, $port),
                'current' => $workspace->id === $current,
            ];
        }
        usort($links, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return \count($links) < 2 ? [] : $links;
    }
}
