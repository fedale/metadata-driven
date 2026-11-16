<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use Symfony\Component\Routing\Attribute\Route;

/**
 * The workflows of the workspace, for whoever holds admin.workflows.
 */
#[Route('/admin/workflows', name: 'admin_workflow_')]
final class WorkflowAdminController extends AbstractWorkflowController
{
    protected function routes(): string
    {
        return 'admin_workflow_';
    }

    protected function isConsole(): bool
    {
        return false;
    }

    protected function denyUnlessAllowed(): void
    {
        if (!$this->access->canManageWorkflows()) {
            throw $this->createAccessDeniedException('No permission to manage the workflows.');
        }
    }
}
