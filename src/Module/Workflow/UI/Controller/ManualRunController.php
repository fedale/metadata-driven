<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Workflow\Application\Definition\FormInput;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\ManualRunRefused;
use App\Module\Workflow\Application\ManualRuns;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {
    }

    #[Route('/w/{workflowId}/run/{recordId}', name: 'workflow_run_record', requirements: ['workflowId' => Requirement::UUID, 'recordId' => Requirement::UUID], methods: ['GET', 'POST'])]
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
                $data = $form->getData();
                $answers = [];
                foreach (\is_array($data) ? $data : [] as $name => $answer) {
                    $answers[(string) $name] = $answer;
                }
                $run = $this->runs->run($workflow, $recordId, $answers);
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

    private function flash(ActiveWorkflow $workflow, RunLog $run): void
    {
        $label = (string) $workflow->definition->trigger->label;
        if (RunLog::COMPLETED === $run->status) {
            $this->addFlash('success', $this->translator->trans('workflow.run.completed', ['%label%' => $label]));

            return;
        }
        $this->addFlash('error', $this->translator->trans('workflow.run.failed', ['%label%' => $label, '%error%' => (string) $run->error]));
    }

    /**
     * Its inputs, checked again by ManualRuns: the form is for the page.
     *
     * @return FormInterface<mixed>
     */
    private function form(ActiveWorkflow $workflow): FormInterface
    {
        // The stateless token the record's page posts too (csrf_token('submit')).
        $builder = $this->forms->createNamedBuilder(self::FORM, FormType::class, null, ['translation_domain' => false, 'csrf_token_id' => 'submit']);
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
