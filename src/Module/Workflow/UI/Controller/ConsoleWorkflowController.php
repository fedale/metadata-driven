<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use App\Shared\Workspace\SystemConsole;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The system admin's workflows, on the platform's events, in the system console: the system
 * session, ROLE_SYSTEM_ADMIN (security.yaml guards /console).
 */
#[Route('/console/workflows', name: 'console_workflow_')]
final class ConsoleWorkflowController extends AbstractWorkflowController
{
    private const string PLATFORM_EXAMPLE = <<<'YAML'
        # Every new workspace gets a package.
        trigger: { on: workspace_created }   # on: workspace_created, member_added
        steps:
          - key: app
            type: install_package             # or assign_role: { email: '{{ $trigger.member.email }}', role: ROLE_CLERK }
            package: fedale/registration
        YAML;

    protected function routes(): string
    {
        return 'console_workflow_';
    }

    protected function isConsole(): bool
    {
        return true;
    }

    protected function denyUnlessAllowed(): void
    {
        // The firewall's rule already; checked again, the pages being the admin's too.
        if (!$this->isGranted(SystemConsole::ROLE)) {
            throw $this->createAccessDeniedException('The system admin\'s workflows are the system admins\'.');
        }
    }

    protected function example(): string
    {
        return self::PLATFORM_EXAMPLE;
    }
}
