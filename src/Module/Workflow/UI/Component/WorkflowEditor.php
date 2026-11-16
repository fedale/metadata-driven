<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Component;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\FieldType\Contract\Dto\StatusMachine;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordLabel;
use App\Module\Workflow\Application\Definition\Condition;
use App\Module\Workflow\Application\Definition\Definition;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\FormInput;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\Definition\TriggerEvent;
use App\Module\Workflow\Application\EditorModel;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\PathCatalog;
use App\Module\Workflow\Application\TestRefused;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Application\WorkflowTester;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Shared\Workspace\SystemConsole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The visual editor of a workflow's draft: the trigger, then the steps as a list, the branches of
 * an if indented under it. The server holds the model (EditorModel) and renders it again on each
 * change; saving writes the draft's YAML again, its comments lost.
 *
 * Paths in the actions name a place in the model as in the definition: "steps.0.branches.1.steps".
 */
#[AsLiveComponent('WorkflowEditor', template: 'admin/workflow_editor.html.twig')]
final class WorkflowEditor extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $workflowId = '';

    /** The system admin's, in the console: the platform's events and steps, no objects. */
    #[LiveProp]
    public bool $console = false;

    #[LiveProp(writable: true)]
    public string $name = '';

    #[LiveProp(writable: true)]
    public string $description = '';

    /** @var array<mixed> */
    #[LiveProp(writable: true)]
    public array $model = [];

    /** @var list<array{string, string}> the problems of the last activation or test, by path */
    #[LiveProp]
    public array $problems = [];

    #[LiveProp(writable: true)]
    public string $testRecord = '';

    public ?RunLog $test = null;

    public ?string $testError = null;

    public function __construct(
        private readonly WorkflowManager $manager,
        private readonly WorkflowRepository $workflows,
        private readonly AccessCheckerInterface $access,
        private readonly MetadataRegistryInterface $metadata,
        private readonly WorkspaceRolesInterface $roles,
        private readonly PathCatalog $paths,
        private readonly WorkflowTester $tester,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requests,
        private readonly ActiveWorkflows $active,
    ) {
    }

    public function mount(string $workflowId, bool $console = false): void
    {
        $this->console = $console;
        $this->guard();
        $workflow = $this->workflow($workflowId);
        $this->workflowId = $workflowId;
        $this->name = $workflow->getName();
        $this->description = (string) $workflow->getDescription();
        $source = $this->manager->draft($workflow)?->getSource() ?? $this->manager->activeVersion($workflow)?->getSource() ?? '';
        $this->model = EditorModel::fromSource($source) ?? throw new \LogicException('The YAML cannot be read: edit it in the YAML tab.');
    }

    /**
     * Every request of the component, not only its actions: the model it holds is the workflow's.
     */
    #[PostHydrate]
    public function guard(): void
    {
        if ($this->console) {
            // The console's host, its system session, its system admins.
            if (true !== $this->requests->getCurrentRequest()?->attributes->get(SystemConsole::REQUEST_ATTRIBUTE) || !$this->isGranted(SystemConsole::ROLE)) {
                throw $this->createAccessDeniedException('The system admin\'s workflows are edited in the system console.');
            }

            return;
        }
        if (!$this->access->canManageWorkflows()) {
            throw $this->createAccessDeniedException('No permission to manage the workflows.');
        }
    }

    #[LiveAction]
    public function addStep(#[LiveArg] string $at, #[LiveArg] string $type): void
    {
        $steps = $this->listAt($at);
        $steps[] = EditorModel::newStep(StepType::from($type), EditorModel::keys($this->model));
        $this->setAt($at, $steps);
    }

    #[LiveAction]
    public function removeStep(#[LiveArg] string $at): void
    {
        [$list, $index] = self::split($at);
        $steps = $this->listAt($list);
        array_splice($steps, $index, 1);
        $this->setAt($list, $steps);
    }

    #[LiveAction]
    public function moveStep(#[LiveArg] string $at, #[LiveArg] int $by): void
    {
        [$list, $index] = self::split($at);
        $steps = $this->listAt($list);
        $to = $index + $by;
        if (isset($steps[$index], $steps[$to])) {
            [$steps[$index], $steps[$to]] = [$steps[$to], $steps[$index]];
            $this->setAt($list, array_values($steps));
        }
    }

    /**
     * A branch with a condition, before the else when there is one.
     */
    #[LiveAction]
    public function addBranch(#[LiveArg] string $at): void
    {
        $branches = $this->listAt($at.'.branches');
        $branch = ['when' => EditorModel::newCondition(), 'steps' => []];
        $last = end($branches);
        \is_array($last) && null === ($last['when'] ?? null) ? array_splice($branches, \count($branches) - 1, 0, [$branch]) : $branches[] = $branch;
        $this->setAt($at.'.branches', $branches);
    }

    #[LiveAction]
    public function removeBranch(#[LiveArg] string $at, #[LiveArg] int $index): void
    {
        $branches = $this->listAt($at.'.branches');
        array_splice($branches, $index, 1);
        $this->setAt($at.'.branches', $branches);
    }

    /**
     * $at: a search, "steps.0".
     */
    #[LiveAction]
    public function addWhere(#[LiveArg] string $at): void
    {
        $where = $this->listAt($at.'.where');
        $where[] = EditorModel::newWhere();
        $this->setAt($at.'.where', $where);
    }

    #[LiveAction]
    public function removeWhere(#[LiveArg] string $at, #[LiveArg] int $index): void
    {
        $where = $this->listAt($at.'.where');
        array_splice($where, $index, 1);
        $this->setAt($at.'.where', $where);
    }

    #[LiveAction]
    public function addInput(): void
    {
        $form = $this->listAt('trigger.form');
        $form[] = EditorModel::newInput();
        $this->setAt('trigger.form', $form);
    }

    #[LiveAction]
    public function removeInput(#[LiveArg] int $index): void
    {
        $form = $this->listAt('trigger.form');
        array_splice($form, $index, 1);
        $this->setAt('trigger.form', $form);
    }

    /**
     * @return list<string>
     */
    public function getInputTypes(): array
    {
        return FormInput::TYPES;
    }

    /**
     * $list: values (a write's, a return's) or input (a run_workflow's).
     */
    #[LiveAction]
    public function addValue(#[LiveArg] string $at, #[LiveArg] string $list = 'values'): void
    {
        $list = 'input' === $list ? 'input' : 'values';
        $values = $this->listAt($at.'.'.$list);
        $values[] = ['field' => '', 'value' => ''];
        $this->setAt($at.'.'.$list, $values);
    }

    #[LiveAction]
    public function removeValue(#[LiveArg] string $at, #[LiveArg] int $index, #[LiveArg] string $list = 'values'): void
    {
        $list = 'input' === $list ? 'input' : 'values';
        $values = $this->listAt($at.'.'.$list);
        array_splice($values, $index, 1);
        $this->setAt($at.'.'.$list, $values);
    }

    /**
     * The workflows a run_workflow step may run: the active ones on called, by name.
     *
     * @return list<string>
     */
    public function getCallable(): array
    {
        return $this->console ? [] : array_map(static fn (ActiveWorkflow $workflow): string => $workflow->name, $this->active->called());
    }

    /**
     * The inputs of a workflow on called, by name.
     *
     * @return list<string>
     */
    public function inputsOf(string $workflow): array
    {
        $called = '' === $workflow || $this->console ? null : $this->active->byName($workflow);

        return null === $called ? [] : array_map(static fn (FormInput $input): string => $input->name, $called->definition->trigger->form);
    }

    /**
     * $at: a condition, "steps.0.when" or "steps.0.branches.1.when".
     */
    #[LiveAction]
    public function addTest(#[LiveArg] string $at): void
    {
        $tests = $this->listAt($at.'.tests');
        $tests[] = EditorModel::newTest();
        $this->setAt($at.'.tests', $tests);
    }

    #[LiveAction]
    public function removeTest(#[LiveArg] string $at, #[LiveArg] int $index): void
    {
        $tests = $this->listAt($at.'.tests');
        array_splice($tests, $index, 1);
        $this->setAt($at.'.tests', $tests);
    }

    #[LiveAction]
    public function save(): ?Response
    {
        try {
            $this->saveDraft($this->workflow($this->workflowId));
        } catch (InvalidWorkflowDefinition $e) {
            // A name missing or taken.
            $this->problems = self::problems($e);

            return null;
        }
        $this->addFlash('success', $this->translator->trans('admin.workflow.draft_saved'));

        return $this->redirectToRoute(($this->console ? 'console_workflow_' : 'admin_workflow_').'edit', ['id' => $this->workflowId], Response::HTTP_SEE_OTHER);
    }

    #[LiveAction]
    public function activate(): ?Response
    {
        $workflow = $this->workflow($this->workflowId);
        try {
            $this->saveDraft($workflow);
            $version = $this->manager->activate($workflow);
        } catch (InvalidWorkflowDefinition $e) {
            $this->problems = self::problems($e);

            return null;
        }
        $this->addFlash('success', $this->translator->trans('admin.workflow.activated', ['%number%' => $version->getNumber()]));

        return $this->redirectToRoute(($this->console ? 'console_workflow_' : 'admin_workflow_').'edit', ['id' => $this->workflowId], Response::HTTP_SEE_OTHER);
    }

    #[LiveAction]
    public function runTest(): void
    {
        $workflow = $this->workflow($this->workflowId);
        $this->problems = [];
        try {
            $draft = $this->saveDraft($workflow);
            $this->test = $this->tester->test($workflow->getName(), $draft, '' === $this->testRecord ? null : $this->testRecord);
        } catch (InvalidWorkflowDefinition $e) {
            $this->problems = self::problems($e);
        } catch (TestRefused $e) {
            $this->testError = $this->translator->trans('admin.workflow.test_'.$e->reason, ['%object%' => $e->object]);
        }
    }

    /**
     * @return list<ObjectDefinitionDto>
     */
    public function getObjects(): array
    {
        return $this->console ? [] : array_values($this->metadata->allObjects());
    }

    /**
     * The fields of an object, by name; none for an object there is not.
     *
     * @return list<string>
     */
    public function fieldsOf(string $object): array
    {
        return $this->metadata->hasObject($object) ? array_map(strval(...), array_keys($this->metadata->getObject($object)->fields)) : [];
    }

    /**
     * The Status fields of an object, by name.
     *
     * @return list<string>
     */
    public function statusFieldsOf(string $object): array
    {
        if ($this->console || !$this->metadata->hasObject($object)) {
            return [];
        }

        return array_map(strval(...), array_keys(array_filter($this->metadata->getObject($object)->fields, static fn ($field): bool => 'status' === $field->type)));
    }

    /**
     * The transitions of an object's Status field, by name.
     *
     * @return list<string>
     */
    public function transitionsOf(string $object, string $field): array
    {
        if (!\in_array($field, $this->statusFieldsOf($object), true)) {
            return [];
        }

        return array_map(strval(...), array_keys(StatusMachine::fromSettings($this->metadata->getObject($object)->field($field)->settings)->transitions));
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->console ? [] : array_map(strval(...), array_keys($this->roles->roles()));
    }

    /**
     * @return list<string>
     */
    public function getStepTypes(): array
    {
        return array_column(array_filter(StepType::cases(), fn (StepType $type): bool => $this->console ? $type->forPlatform() : !$type->isPlatform()), 'value');
    }

    /**
     * @return list<string>
     */
    public function getOperators(): array
    {
        return Condition::OPERATORS;
    }

    /**
     * @return list<string>
     */
    public function getTriggerEvents(): array
    {
        return array_column(array_filter(TriggerEvent::cases(), fn (TriggerEvent $on): bool => $this->console === $on->isPlatform()), 'value');
    }

    /**
     * @return array{trigger: list<string>, context: list<string>, steps: array<string, list<string>>}
     */
    public function getPaths(): array
    {
        return $this->paths->paths($this->definition());
    }

    /**
     * @return list<RecordLabel>
     */
    public function getRecords(): array
    {
        return $this->console ? [] : $this->tester->records($this->definition());
    }

    /**
     * The problems of a step, a branch or a condition: those whose path starts with its own.
     *
     * @return list<string>
     */
    public function problemsAt(string $path): array
    {
        $messages = [];
        foreach ($this->problems as [$at, $message]) {
            if ($at === $path || str_starts_with($at, $path.'.')) {
                $messages[] = ('' === $at ? '' : $at.': ').$message;
            }
        }

        return $messages;
    }

    private function saveDraft(Workflow $workflow): string
    {
        $this->manager->describe($workflow, $this->name, $this->description);
        $source = EditorModel::toSource($this->model);
        $this->manager->saveDraft($workflow, $source);

        return $source;
    }

    private function definition(): ?Definition
    {
        try {
            return (new DefinitionParser())->parse(EditorModel::toSource($this->model));
        } catch (InvalidWorkflowDefinition) {
            return null;
        }
    }

    private function workflow(string $id): Workflow
    {
        return $this->workflows->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('No such workflow.');
    }

    /**
     * @return list<mixed>
     */
    private function listAt(string $path): array
    {
        $value = $this->model;
        foreach (explode('.', $path) as $segment) {
            $value = \is_array($value) ? ($value[$segment] ?? []) : [];
        }

        return \is_array($value) ? array_values($value) : [];
    }

    /**
     * @param list<mixed> $value
     */
    private function setAt(string $path, array $value): void
    {
        $this->model = self::put($this->model, explode('.', $path), $value);
    }

    /**
     * The array with the value put at the path, the arrays on the way made where missing.
     *
     * @param array<mixed> $node
     * @param list<string> $path
     *
     * @return array<mixed>
     */
    private static function put(array $node, array $path, mixed $value): array
    {
        $segment = array_shift($path);
        if (null === $segment) {
            return $node;
        }
        $key = ctype_digit($segment) ? (int) $segment : $segment;
        $node[$key] = [] === $path ? $value : self::put(\is_array($node[$key] ?? null) ? $node[$key] : [], $path, $value);

        return $node;
    }

    /**
     * "steps.0.branches.1.steps.2" → ["steps.0.branches.1.steps", 2].
     *
     * @return array{string, int}
     */
    private static function split(string $at): array
    {
        $cut = (int) strrpos($at, '.');

        return [substr($at, 0, $cut), (int) substr($at, $cut + 1)];
    }

    /**
     * @return list<array{string, string}>
     */
    private static function problems(InvalidWorkflowDefinition $e): array
    {
        $problems = [];
        foreach ($e->errors as $path => $messages) {
            foreach ($messages as $message) {
                $problems[] = [$path, $message];
            }
        }

        return $problems;
    }
}
