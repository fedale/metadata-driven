<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\CascadeTargets;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordLabel;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Dto\ReferencingGroup;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordLookupInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Record\UI\Form\RecordFormFactory;
use App\Module\Record\UI\Grid\RecordGridFactory;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/o/{object}', name: 'record_', requirements: ['object' => '[a-z][a-z0-9_]*'])]
final class RecordController extends AbstractController
{
    private const int LOOKUP_LIMIT = 20;
    // The gridview relation filter loads its options once, without a search term.
    private const int FILTER_OPTIONS_LIMIT = 200;

    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordManagerInterface $records,
        private readonly RecordFinderInterface $finder,
        private readonly RecordFormFactory $forms,
        private readonly RecordGridFactory $grids,
        private readonly AccessCheckerInterface $access,
        private readonly CascadeTargets $cascadeTargets,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The grid of the object: its default grid view, else one column per field.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $object): Response
    {
        $definition = $this->objectFor($object, RecordAction::Read);

        return $this->renderGridOf($definition, $definition->view(ViewKind::Grid));
    }

    /**
     * The grid of a grid view named in the path: the gridview bundle keeps the route
     * parameters in its sort, page and filter links ("?view=" is its own: table or cards).
     */
    #[Route('/grid/{grid}', name: 'grid', requirements: ['grid' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function grid(string $object, string $grid): Response
    {
        $definition = $this->objectFor($object, RecordAction::Read);

        return $this->renderGridOf($definition, $this->gridViewOr404($definition, $grid));
    }

    /**
     * Downloads the rows of the current filters, search and sort, with the columns on screen.
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    #[Route('/grid/{grid}/export', name: 'grid_export', requirements: ['grid' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function export(string $object, Request $request, GridExporterRegistry $exporters, ?string $grid = null): Response
    {
        $definition = $this->objectFor($object, RecordAction::Read);
        $format = $request->query->getString('format', 'csv');
        if (!$exporters->has($format)) {
            throw $this->createNotFoundException(\sprintf('Unknown export format "%s".', $format));
        }

        $grid = $this->grids->create($definition, null === $grid ? $definition->view(ViewKind::Grid) : $this->gridViewOr404($definition, $grid));
        // The page reports the visible columns, in their order, as "cols=a,b,c".
        $cols = $request->query->all()['cols'] ?? null;
        $visible = \is_string($cols) && '' !== trim($cols) ? array_values(array_filter(array_map(trim(...), explode(',', $cols)))) : null;

        return $exporters->get($format)->export(
            $grid->getExportRows(),
            $grid->getExportColumns($visible),
            ['filename' => $definition->name],
        );
    }

    /**
     * Records to pick in a relation field (Symfony UX Autocomplete format).
     *
     * "filter[<relation field>]=<id>" keeps the records pointing at that record (the
     * municipalities of a province); an empty or invalid id keeps none: nothing chosen yet.
     */
    #[Route('/lookup', name: 'lookup', methods: ['GET'])]
    public function lookup(string $object, Request $request, RecordLookupInterface $lookup): JsonResponse
    {
        $definition = $this->objectFor($object, RecordAction::Read);
        $filters = [];
        foreach ($request->query->all('filter') as $field => $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                return $this->json(['results' => []]);
            }
            $filters[(string) $field] = $id;
        }
        try {
            $records = $lookup->search($definition->name, $request->query->getString('query'), self::LOOKUP_LIMIT, $filters);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return $this->json(['results' => array_map(static fn (RecordLabel $record): array => ['value' => $record->id, 'text' => $record->label], $records)]);
    }

    /**
     * Options of a relation filter in a grid (gridview "ajax_url" format), loaded at once.
     */
    #[Route('/options', name: 'options', methods: ['GET'])]
    public function options(string $object, RecordLookupInterface $lookup): JsonResponse
    {
        $definition = $this->objectFor($object, RecordAction::Read);
        $records = $lookup->search($definition->name, '', self::FILTER_OPTIONS_LIMIT);

        return $this->json(array_map(static fn (RecordLabel $record): array => ['id' => $record->id, 'label' => $record->label], $records));
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(string $object, Request $request): Response
    {
        $definition = $this->objectFor($object, RecordAction::Create);
        $form = $this->forms->create($definition)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->records->create(new RecordInput($definition->name, $this->submitted($form)));
                $this->addFlash('success', $this->translator->trans('flash.created', ['%object%' => $definition->labelSingular]));

                return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
            } catch (RecordValidationFailed $failure) {
                $this->forms->addErrors($form, $failure);
            }
        }

        return $this->render('record/form.html.twig', [
            'object' => $definition,
            'form' => $form,
            'record' => null,
            'layout' => $this->formLayout($definition, $request),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $object, string $id, Request $request): Response
    {
        // Reading opens the page; saving needs the update permission.
        $definition = $this->objectFor($object, $request->isMethod('POST') ? RecordAction::Update : RecordAction::Read);
        $record = $this->recordOr404($definition, $id);
        $canUpdate = $this->access->canRecords($definition->name, RecordAction::Update);
        $form = $this->forms->create($definition, $record, readOnly: !$canUpdate)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->records->update($id, new RecordInput($definition->name, $this->submitted($form)));
                $this->addFlash('success', $this->translator->trans('flash.updated', ['%object%' => $definition->labelSingular]));

                return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
            } catch (RecordValidationFailed $failure) {
                $this->forms->addErrors($form, $failure);
            }
        }

        return $this->render('record/form.html.twig', [
            'object' => $definition,
            'form' => $form,
            'record' => $record,
            'layout' => $this->formLayout($definition, $request),
            'delete_form' => $this->deleteForm($definition, $id),
            // Only the related records the user may read.
            'referencing' => array_values(array_filter(
                $this->finder->referencing($definition->name, $id),
                fn (ReferencingGroup $group): bool => $this->access->canRecords($group->objectName, RecordAction::Read),
            )),
            'can_update' => $canUpdate,
            'can_delete' => $this->access->canRecords($definition->name, RecordAction::Delete),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $object, string $id, Request $request): Response
    {
        $definition = $this->objectFor($object, RecordAction::Delete);
        // A cascade deletes records of other objects: it needs their delete permission too.
        foreach ($this->cascadeTargets->of($definition->name) as $target) {
            if (!$this->access->canRecords($target->name, RecordAction::Delete)) {
                $this->addFlash('error', $this->translator->trans('flash.cascade_denied', ['%objects%' => $target->labelPlural]));

                return $this->redirectToRoute('record_edit', ['object' => $definition->name, 'id' => $id], Response::HTTP_SEE_OTHER);
            }
        }
        $form = $this->deleteForm($definition, $id)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->records->delete($definition->name, $id);
            } catch (RecordNotFound $e) {
                throw $this->createNotFoundException($e->getMessage(), $e);
            } catch (RecordDeleteRestricted $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('record_edit', ['object' => $definition->name, 'id' => $id], Response::HTTP_SEE_OTHER);
            }
            $this->addFlash('success', $this->translator->trans('flash.deleted', ['%object%' => $definition->labelSingular]));
        }

        return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
    }

    /**
     * The object, if it exists (404) and the user may do $action on its records (403).
     */
    private function objectFor(string $name, RecordAction $action): ObjectDefinitionDto
    {
        try {
            $object = $this->metadata->getObject($name);
        } catch (ObjectDefinitionNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        if (!$this->access->canRecords($object->name, $action)) {
            throw $this->createAccessDeniedException(\sprintf('No permission to %s the records of "%s".', $action->value, $object->name));
        }

        return $object;
    }

    private function renderGridOf(ObjectDefinitionDto $object, ?ViewDefinitionDto $view): Response
    {
        return $this->grids->create($object, $view)->renderGrid('record/index.html.twig', [
            'object' => $object,
            'grid_view' => $view,
            'can_create' => $this->access->canRecords($object->name, RecordAction::Create),
        ]);
    }

    private function gridViewOr404(ObjectDefinitionDto $object, string $name): ViewDefinitionDto
    {
        return $object->view(ViewKind::Grid, $name)
            ?? throw $this->createNotFoundException(\sprintf('Object "%s" has no grid view "%s".', $object->name, $name));
    }

    /**
     * The template of the form view picked by "?view=<name>", else of the default one;
     * null for the plain layout. An unknown view name is a 404.
     */
    private function formLayout(ObjectDefinitionDto $object, Request $request): ?string
    {
        $name = $request->query->getString('view');
        $view = $object->view(ViewKind::Form, '' === $name ? null : $name);
        if ('' !== $name && null === $view) {
            throw $this->createNotFoundException(\sprintf('Object "%s" has no form view "%s".', $object->name, $name));
        }

        return $view?->template;
    }

    private function recordOr404(ObjectDefinitionDto $object, string $id): RecordOutput
    {
        return $this->finder->find($object->name, $id)
            ?? throw $this->createNotFoundException(RecordNotFound::withId($object->name, $id)->getMessage());
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>
     */
    private function submitted(FormInterface $form): array
    {
        $data = [];
        foreach ($form->all() as $child) {
            // A disabled control only shows a value (the rows of a junction object the user
            // may not change, or the labels only).
            if (!$child->isDisabled()) {
                $data[$child->getName()] = $child->getData();
            }
        }

        return $data;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(ObjectDefinitionDto $object, string $id): FormInterface
    {
        return $this->container->get('form.factory')
            ->createNamedBuilder('delete_record', options: [
                'action' => $this->generateUrl('record_delete', ['object' => $object->name, 'id' => $id]),
            ])
            ->getForm();
    }
}
