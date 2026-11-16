<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The workflows of the workspace, for whoever holds admin.workflows.
 */
#[Route('/admin/workflows', name: 'admin_workflow_')]
final class WorkflowAdminController extends AbstractWorkflowController
{
    /**
     * A new address for a workflow on a webhook: its token made again, the old address answers no
     * more; the new one shown once, in the message.
     */
    #[Route('/{id}/webhook', name: 'webhook', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function webhook(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $workflow = $this->workflows->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('No such workflow.');
        $form = $this->forms->createNamedBuilder('webhook_workflow', FormType::class, null, [
            'action' => $this->generateUrl('admin_workflow_webhook', ['id' => $id]),
        ])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $token = $this->webhooks->issue($id, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', $this->translator->trans('admin.workflow.webhook_made', ['%url%' => $this->generateUrl('workflow_webhook', ['workflowId' => $id, 'token' => $token], UrlGeneratorInterface::ABSOLUTE_URL)]));
        }

        return $this->redirectToRoute('admin_workflow_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

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
