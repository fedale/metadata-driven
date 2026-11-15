<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use Fedale\RbacBundle\Contract\TenantContextInterface;

/**
 * The current workspace for fedale/rbac-bundle (tenancy.context in fedale_rbac.yaml): its id, a
 * UUID, which keys the bundle's cache and its graphs in memory. Each workspace has RBAC tables of
 * its own (its rbac_schema, in the search_path), so the bundle needs nothing else: its
 * assignments all stay in its 'global' workspace.
 *
 * With no workspace, 'global': the session reads the empty tables of no_workspace_rbac, and no workspace
 * id is ever 'global'.
 */
final class RbacWorkspaceContext implements TenantContextInterface
{
    public function __construct(private readonly ConnectionCurrentWorkspace $current)
    {
    }

    public function getTenantId(): string
    {
        return $this->current->workspace()->id ?? self::GLOBAL;
    }
}
