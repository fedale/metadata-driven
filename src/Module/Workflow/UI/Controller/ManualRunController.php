<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\RecordSelectionInterface;
use App\Module\Workflow\Application\Definition\FormInput;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\ManualRunRefused;
use App\Module\Workflow\Application\ManualRuns;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A manual workflow run on a record, from its page: its form asked first, if it has one (a
 * workflow without one is posted straight from the record's page). The result is a flash
 * message on the record's page.
 */
final class ManualRunController extends AbstractController
{
    /** The form's name: the record's page posts it too (WorkflowRecordActions). */
    public const string FORM = 'workflow_run';

    public function __construct(
        private readonly ManualRuns $runs,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordSelectionInterface $selection,
        #[Autowire('%app.workflow.max_records%')]
        private readonly int $maxRecords,
    ) {
    }

    #[Route('/w/{workflowId}/run/{recordId}', name: 'workflow_run_record', requirements: ['workflowId' => Requirement::UUID, 'recordId' => KeyType::ROUTE_REQUIREMENT], methods: ['GET', 'POST'])]
    public function run(string $workflowId, string $recordId, Request $request): Response
    {
        try {
            $workflow = $this->runs->get($workflowId);
            if (!$this->runs->mayRun($workflow)) {
                throw $this->createAccessDeniedException();
            }
            $this->runs->record($workflow, $recordId);
        } catch (ManualRunRefused $e) {
            throw ManualRunRefused::FORBIDDEN === $e->getCode() ? $this->createAccessDeniedException($e->getMessage(), $e) : $this->createNotFoundException($e->getMessage(), $e);
        }
        $object = $workflow->definition->trigger->object;
        $back = $this->generateUrl('record_edit', ['object' => $object, 'id' => $recordId]);

        $form = $this->form($workflow)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $run = $this->runs->run($workflow, $recordId, self::answers($form));
                $this->flash($workflow, $run);

                return $this->redirect($back, Response::HTTP_SEE_OTHER);
            } catch (ManualRunRefused $e) {
                if ([] === $e->errors) {
                    throw $this->createAccessDeniedException($e->getMessage(), $e);
                }
                foreach ($e->errors as $name => $error) {
                    ($form->has($name) ? $form->get($name) : $form)->addError(new FormError($error));
                }
            }
        }

        return $this->render('workflow/run.html.twig', [
            'workflow' => $workflow,
            'object' => $this->metadata->getObject($object),
            'form' => $form,
            'back' => $back,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * A manual workflow run on a grid's selection: its page, or (fetched by the bundle's modal) the
     * page's body only. The selection is in the query string, ids[] or all=1 and the grid's
     * filters, and the form posts it back; the result is a flash message on the grid.
     */
    #[Route('/w/{workflowId}/run-selection', name: 'workflow_run_selection', requirements: ['workflowId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function runSelection(string $workflowId, Request $request): Response
    {
        try {
            $workflow = $this->runs->get($workflowId);
        } catch (ManualRunRefused $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        if (null === $workflow->definition->trigger->selection) {
            throw $this->createNotFoundException('This workflow runs on one record at a time.');
        }
        // Before the selection is read: its count alone tells about the records.
        if (!$this->runs->mayRunOnSelection($workflow)) {
            throw $this->createAccessDeniedException();
        }
        $object = $this->metadata->getObject($workflow->definition->trigger->object);
        $grid = $request->query->getString('grid');
        $grid = 1 === preg_match('/^[a-z][a-z0-9_]*$/', $grid) && null !== $object->view(ViewKind::Grid, $grid) ? $grid : null;
        $back = null === $grid
            ? $this->generateUrl('record_index', ['object' => $object->name])
            : $this->generateUrl('record_grid', ['object' => $object->name, 'grid' => $grid]);
        $ids = $this->selection->selected($object->name, $grid, $this->maxRecords);

        // Posted back to this very url: the selection with it.
        $form = $this->form($workflow, $request->getRequestUri())->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->flashSelection($workflow, $this->runs->runOnSelection($workflow, $ids, self::answers($form)));

                return $this->redirect($back, Response::HTTP_SEE_OTHER);
            } catch (ManualRunRefused $e) {
                if (ManualRunRefused::FORBIDDEN === $e->getCode()) {
                    throw $this->createAccessDeniedException($e->getMessage(), $e);
                }
                foreach ([] === $e->errors ? ['' => $e->getMessage()] : $e->errors as $name => $error) {
                    ($form->has($name) ? $form->get($name) : $form)->addError(new FormError($error));
                }
            }
        }

        return $this->render($request->isXmlHttpRequest() ? 'workflow/_run_selection.html.twig' : 'workflow/run_selection.html.twig', [
            'workflow' => $workflow,
            'object' => $object,
            'form' => $form,
            'back' => $back,
            'count' => \count($ids),
            'too_many' => \count($ids) > $this->maxRecords,
            'max' => $this->maxRecords,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @param list<RunLog> $runs
     */
    private function flashSelection(ActiveWorkflow $workflow, array $runs): void
    {
        $label = (string) $workflow->definition->trigger->label;
        // A run stopped at a wait goes on later: it did not fail.
        $waiting = array_values(array_filter($runs, static fn (RunLog $run): bool => RunLog::WAITING === $run->status));
        $failed = array_values(array_filter($runs, static fn (RunLog $run): bool => !\in_array($run->status, [RunLog::COMPLETED, RunLog::WAITING], true)));
        $done = \count($runs) - \count($failed) - \count($waiting);
        if ($workflow->definition->trigger->runsOnAll()) {
            $this->flash($workflow, $runs[0]);

            return;
        }
        if ($done > 0) {
            $this->addFlash('success', $this->translator->trans('workflow.run.done_on', ['%label%' => $label, '%count%' => $done]));
        }
        if ([] !== $waiting) {
            $this->addFlash('success', $this->translator->trans('workflow.run.waiting_on', ['%label%' => $label, '%count%' => \count($waiting), '%until%' => $waiting[0]->resumeAt?->format('Y-m-d H:i')]));
        }
        if ([] !== $failed) {
            $this->addFlash('error', $this->translator->trans('workflow.run.failed_on', ['%label%' => $label, '%count%' => \count($failed), '%error%' => (string) $failed[0]->error]));
        }
    }

    /**
     * The answers of the form, by input name.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>
     */
    private static function answers(FormInterface $form): array
    {
        $answers = [];
        foreach ((array) $form->getData() as $name => $answer) {
            $answers[(string) $name] = $answer;
        }

        return $answers;
    }

    private function flash(ActiveWorkflow $workflow, RunLog $run): void
    {
        $label = (string) $workflow->definition->trigger->label;
        if (RunLog::COMPLETED === $run->status) {
            $this->addFlash('success', $this->translator->trans('workflow.run.completed', ['%label%' => $label]));

            return;
        }
        if (RunLog::WAITING === $run->status) {
            $this->addFlash('success', $this->translator->trans('workflow.run.waiting', ['%label%' => $label, '%until%' => $run->resumeAt?->format('Y-m-d H:i')]));

            return;
        }
        $this->addFlash('error', $this->translator->trans('workflow.run.failed', ['%label%' => $label, '%error%' => (string) $run->error]));
    }

    /**
     * Its inputs, checked again by ManualRuns: the form is for the page.
     *
     * @return FormInterface<mixed>
     */
    private function form(ActiveWorkflow $workflow, ?string $action = null): FormInterface
    {
        // The stateless token the record's page posts too (csrf_token('submit')).
        $builder = $this->forms->createNamedBuilder(self::FORM, FormType::class, null, ['translation_domain' => false, 'csrf_token_id' => 'submit', ...(null === $action ? [] : ['action' => $action])]);
        foreach ($workflow->definition->trigger->form as $input) {
            $builder->add($input->name, ...self::field($input));
        }

        return $builder->getForm();
    }

    /**
     * @return array{class-string<FormTypeInterface<mixed>>, array<string, mixed>}
     */
    private static function field(FormInput $input): array
    {
        $options = ['label' => $input->label, 'required' => $input->required];
        $required = $input->required ? [new NotBlank()] : [];

        return match ($input->type) {
            'number' => [NumberType::class, $options + ['html5' => true, 'constraints' => $required]],
            'date' => [DateType::class, $options + ['widget' => 'single_text', 'input' => 'string', 'constraints' => $required]],
            'boolean' => [CheckboxType::class, $options + ['constraints' => $input->required ? [new IsTrue()] : []]],
            'select' => [ChoiceType::class, $options + ['choices' => array_combine($input->options, $input->options), 'placeholder' => $input->required ? false : '', 'constraints' => $required]],
            default => [TextType::class, $options + ['constraints' => $required]],
        };
    }
}
