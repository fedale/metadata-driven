<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Workflow\Application\Secrets\SecretsKeyMissing;
use App\Module\Workflow\Application\Secrets\WorkflowSecrets;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The workspace's secrets for its workflows' HTTP requests (admin.workflows): their names; a
 * value written once, never shown again, replaced or removed.
 */
#[Route('/admin/workflows/secrets', name: 'admin_workflow_secret_')]
final class WorkflowSecretController extends AbstractController
{
    public function __construct(
        private readonly WorkflowSecrets $secrets,
        private readonly AccessCheckerInterface $access,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAllowed();
        $form = $this->forms->createNamedBuilder('secret', FormType::class)
            ->add('name', TextType::class, ['label' => 'admin.workflow.secret_name', 'help' => 'admin.workflow.secret_name_help', 'constraints' => [new NotBlank()]])
            ->add('value', PasswordType::class, ['label' => 'admin.workflow.secret_value', 'help' => 'admin.workflow.secret_value_help', 'constraints' => [new NotBlank()]])
            ->getForm()
            ->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $name = self::text($form, 'name');
            try {
                $this->secrets->set($name, self::text($form, 'value'), $this->getUser()?->getUserIdentifier());
                $this->addFlash('success', $this->translator->trans('admin.workflow.secret_saved', ['%name%' => $name]));

                return $this->redirectToRoute('admin_workflow_secret_index', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $form->get('name')->addError(new FormError($e->getMessage()));
            } catch (SecretsKeyMissing $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/workflow_secrets.html.twig', [
            'form' => $form,
            'secrets' => array_map(fn (array $secret): array => $secret + ['delete' => $this->deleteForm($secret['name'])->createView()], $this->secrets->all()),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{name}/delete', name: 'delete', requirements: ['name' => '[a-z][a-z0-9_]{0,63}'], methods: ['POST'])]
    public function delete(string $name, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $form = $this->deleteForm($name)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->secrets->remove($name)) {
            $this->addFlash('success', $this->translator->trans('admin.workflow.secret_deleted', ['%name%' => $name]));
        }

        return $this->redirectToRoute('admin_workflow_secret_index', [], Response::HTTP_SEE_OTHER);
    }

    private function denyUnlessAllowed(): void
    {
        if (!$this->access->canManageWorkflows()) {
            throw $this->createAccessDeniedException('No permission to manage the workflows.');
        }
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(string $name): FormInterface
    {
        return $this->forms->createNamedBuilder('delete_secret_'.$name, FormType::class, null, [
            'action' => $this->generateUrl('admin_workflow_secret_delete', ['name' => $name]),
        ])->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function text(FormInterface $form, string $name): string
    {
        $value = $form->get($name)->getData();

        return \is_string($value) ? $value : '';
    }
}
