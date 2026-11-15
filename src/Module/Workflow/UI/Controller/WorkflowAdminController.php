<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\RunQueries;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Model\VersionStatus;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Model\WorkflowVersion;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The workflows of the workspace (admin.workflows): the list in the order they run, the editor
 * of a workflow's draft (YAML), its versions, its runs.
 */
#[Route('/admin/workflows', name: 'admin_workflow_')]
final class WorkflowAdminController extends AbstractController
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const string EXAMPLE = <<<'YAML'
        # When a person is created, after the write: a welcome pet for them.
        trigger: { on: created, object: person, when: after }   # on: created, updated, created_or_updated, deleted; when: before or after
        steps:
          - key: named                                            # a step's key names its output: {{ named.passed }}
            type: filter                                          # goes on only if the condition holds
            when: { field: $trigger.record.full_name, op: not_empty }
          - key: pet
            type: create_record
            object: pet
            values: { name: 'Pet of {{ $trigger.record.full_name }}', owner: '{{ $trigger.record.id }}' }
            # act_as: ROLE_CLERK                                  # else, as whoever triggered the event
        YAML;

    public function __construct(
        private readonly WorkflowManager $manager,
        private readonly WorkflowRepository $workflows,
        private readonly RunQueries $runs,
        private readonly MetadataRegistryInterface $metadata,
        private readonly AccessCheckerInterface $access,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAllowed();
        $workflows = $this->workflows->all();

        return $this->render('admin/workflows.html.twig', [
            'rows' => array_map(fn (Workflow $workflow): array => [
                'workflow' => $workflow,
                'active' => $this->manager->activeVersion($workflow),
                'draft' => $this->manager->draft($workflow),
                'trigger' => $this->trigger($this->manager->activeVersion($workflow) ?? $this->manager->draft($workflow)),
                'move' => $this->moveForm($workflow)->createView(),
            ], $workflows),
            'summary' => $this->runs->summary(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessAllowed();
        $form = $this->editor(null, self::EXAMPLE)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $workflow = $this->manager->create($this->text($form, 'name'), $this->text($form, 'description'), $this->text($form, 'definition'));
                $this->addFlash('success', $this->translator->trans('admin.workflow.created', ['%name%' => $workflow->getName()]));

                return $this->redirectToRoute('admin_workflow_edit', ['id' => $workflow->getId()->toRfc4122()], Response::HTTP_SEE_OTHER);
            } catch (InvalidWorkflowDefinition $e) {
                $this->errors($form, $e);
            }
        }

        return $this->render('admin/workflow_edit.html.twig', $this->editorPage(null, $form, []));
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => self::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $workflow = $this->workflowOr404($id);
        $active = $this->manager->activeVersion($workflow);
        $source = $this->manager->draft($workflow)?->getSource() ?? $active?->getSource() ?? self::EXAMPLE;
        $form = $this->editor($workflow, $source)->handleRequest($request);
        $problems = [];

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->manager->describe($workflow, $this->text($form, 'name'), $this->text($form, 'description'));
                $this->manager->saveDraft($workflow, $this->text($form, 'definition'));
                $activate = $form->get('activate');
                if ($activate instanceof SubmitButton && $activate->isClicked()) {
                    $version = $this->manager->activate($workflow);
                    $this->addFlash('success', $this->translator->trans('admin.workflow.activated', ['%number%' => $version->getNumber()]));
                } else {
                    $this->addFlash('success', $this->translator->trans('admin.workflow.draft_saved'));
                }

                return $this->redirectToRoute('admin_workflow_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (InvalidWorkflowDefinition $e) {
                $problems = $this->errors($form, $e);
            }
        }

        return $this->render('admin/workflow_edit.html.twig', [
            ...$this->editorPage($workflow, $form, $problems),
            'active' => $active,
            'draft' => $this->manager->draft($workflow),
            'deactivate_form' => $this->actionForm('deactivate', $workflow)->createView(),
            'delete_form' => $this->actionForm('delete', $workflow)->createView(),
        ]);
    }

    #[Route('/{id}/deactivate', name: 'deactivate', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function deactivate(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $workflow = $this->workflowOr404($id);
        $form = $this->actionForm('deactivate', $workflow)->handleRequest($request);
        // Valid: its token checked, as every form that changes something.
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->deactivate($workflow);
            $this->addFlash('success', $this->translator->trans('admin.workflow.deactivated'));
        }

        return $this->redirectToRoute('admin_workflow_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $workflow = $this->workflowOr404($id);
        $form = $this->actionForm('delete', $workflow)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->delete($workflow);
            $this->addFlash('success', $this->translator->trans('admin.workflow.deleted', ['%name%' => $workflow->getName()]));
        }

        return $this->redirectToRoute('admin_workflow_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * One place up or down in the list: the order the workflows of one event run in.
     */
    #[Route('/{id}/move', name: 'move', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function move(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $workflow = $this->workflowOr404($id);
        $form = $this->moveForm($workflow)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $ordered = $this->workflows->all();
            $at = array_search($workflow, $ordered, true);
            $up = $form->get('up');
            $to = $up instanceof SubmitButton && $up->isClicked() ? $at - 1 : $at + 1;
            if (\is_int($at) && isset($ordered[$to])) {
                [$ordered[$at], $ordered[$to]] = [$ordered[$to], $ordered[$at]];
                $this->manager->reorder(array_values($ordered));
            }
        }

        return $this->redirectToRoute('admin_workflow_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/versions', name: 'versions', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function versions(string $id): Response
    {
        $this->denyUnlessAllowed();
        $workflow = $this->workflowOr404($id);

        return $this->render('admin/workflow_versions.html.twig', [
            'workflow' => $workflow,
            'versions' => array_map(fn (WorkflowVersion $version): array => [
                'version' => $version,
                'reuse' => \in_array($version->getStatus(), [VersionStatus::Archived, VersionStatus::Deactivated], true) ? $this->reuseForm($version)->createView() : null,
            ], $this->workflows->versionsOf($workflow)),
        ]);
    }

    #[Route('/versions/{id}/reuse', name: 'reuse', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function reuse(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $version = $this->workflows->findVersion(Uuid::fromString($id)) ?? throw $this->createNotFoundException('No such version.');
        $form = $this->reuseForm($version)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->useAsDraft($version);
            $this->addFlash('success', $this->translator->trans('admin.workflow.reused', ['%number%' => $version->getNumber()]));
        }

        return $this->redirectToRoute('admin_workflow_edit', ['id' => $version->getWorkflow()->getId()->toRfc4122()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/runs', name: 'runs', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function runs(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $workflow = $this->workflowOr404($id);
        $status = $request->query->getString('status');
        $status = \in_array($status, ['completed', 'failed', 'refused'], true) ? $status : null;

        return $this->render('admin/workflow_runs.html.twig', [
            'workflow' => $workflow,
            'status' => $status,
            'runs' => $this->runs->runsOf($workflow->getId()->toRfc4122(), $status),
        ]);
    }

    #[Route('/runs/{id}', name: 'run', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function run(string $id): Response
    {
        $this->denyUnlessAllowed();
        $run = $this->runs->run($id) ?? throw $this->createNotFoundException('No such run.');

        return $this->render('admin/workflow_run.html.twig', [
            'run' => $run,
            'workflow' => $this->workflows->find(Uuid::fromString($run['workflow_id'])),
        ]);
    }

    private function denyUnlessAllowed(): void
    {
        if (!$this->access->canManageWorkflows()) {
            throw $this->createAccessDeniedException('No permission to manage the workflows.');
        }
    }

    private function workflowOr404(string $id): Workflow
    {
        return $this->workflows->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('No such workflow.');
    }

    /**
     * What the version's trigger says, read back from its source: "person, created, after".
     */
    private function trigger(?WorkflowVersion $version): ?string
    {
        if (null === $version) {
            return null;
        }
        try {
            $trigger = (null === $version->getDefinition() ? (new DefinitionParser())->parse($version->getSource()) : (new DefinitionParser())->fromArray($version->getDefinition()))->trigger;
        } catch (InvalidWorkflowDefinition) {
            return null;
        }

        return \sprintf('%s, %s, %s', $trigger->object, $trigger->on->value, $trigger->when->value);
    }

    /**
     * @param FormInterface<mixed>       $form
     * @param list<array{string,string}> $problems
     *
     * @return array<string, mixed>
     */
    private function editorPage(?Workflow $workflow, FormInterface $form, array $problems): array
    {
        return ['workflow' => $workflow, 'form' => $form, 'problems' => $problems, 'objects' => $this->metadata->allObjects()];
    }

    /**
     * Puts each error on its input when there is one ("name"), and lists the definition's by
     * their path in it.
     *
     * @param FormInterface<mixed> $form
     *
     * @return list<array{string, string}> path, message
     */
    private function errors(FormInterface $form, InvalidWorkflowDefinition $e): array
    {
        $problems = [];
        foreach ($e->errors as $path => $messages) {
            foreach ($messages as $message) {
                if ('name' === $path && $form->has('name')) {
                    $form->get('name')->addError(new FormError($message));
                } else {
                    $problems[] = [$path, $message];
                }
            }
        }
        if ([] !== $problems) {
            $form->get('definition')->addError(new FormError($this->translator->trans('admin.workflow.invalid', ['%count%' => \count($problems)])));
        }

        return $problems;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function editor(?Workflow $workflow, string $source): FormInterface
    {
        $builder = $this->forms->createNamedBuilder('workflow', FormType::class, [
            'name' => $workflow?->getName(),
            'description' => $workflow?->getDescription(),
            'definition' => $source,
        ])
            ->add('name', TextType::class, ['label' => 'admin.workflow.name'])
            ->add('description', TextType::class, ['label' => 'admin.workflow.description', 'required' => false])
            ->add('definition', TextareaType::class, [
                'label' => 'admin.workflow.definition',
                'attr' => ['rows' => 24, 'class' => 'md-code-input', 'spellcheck' => 'false'],
            ]);
        if (null !== $workflow) {
            $builder
                ->add('save', SubmitType::class, ['label' => 'admin.workflow.save_draft'])
                ->add('activate', SubmitType::class, ['label' => 'admin.workflow.save_activate', 'attr' => ['class' => 'gv-btn gv-btn-primary']]);
        }

        return $builder->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function actionForm(string $action, Workflow $workflow): FormInterface
    {
        return $this->forms->createNamedBuilder($action.'_workflow', FormType::class, null, [
            'action' => $this->generateUrl('admin_workflow_'.$action, ['id' => $workflow->getId()->toRfc4122()]),
        ])->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function moveForm(Workflow $workflow): FormInterface
    {
        return $this->forms->createNamedBuilder('move_'.str_replace('-', '', $workflow->getId()->toRfc4122()), FormType::class, null, [
            'action' => $this->generateUrl('admin_workflow_move', ['id' => $workflow->getId()->toRfc4122()]),
        ])
            ->add('up', SubmitType::class, ['label' => '↑', 'attr' => ['class' => 'gv-btn gv-btn-sm', 'aria-label' => $this->translator->trans('admin.workflow.move_up')]])
            ->add('down', SubmitType::class, ['label' => '↓', 'attr' => ['class' => 'gv-btn gv-btn-sm', 'aria-label' => $this->translator->trans('admin.workflow.move_down')]])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function reuseForm(WorkflowVersion $version): FormInterface
    {
        return $this->forms->createNamedBuilder('reuse_'.str_replace('-', '', $version->getId()->toRfc4122()), FormType::class, null, [
            'action' => $this->generateUrl('admin_workflow_reuse', ['id' => $version->getId()->toRfc4122()]),
        ])->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function text(FormInterface $form, string $name): string
    {
        $value = $form->get($name)->getData();

        return \is_string($value) ? $value : '';
    }
}
