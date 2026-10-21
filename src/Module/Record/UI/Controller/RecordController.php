<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\OnDelete;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
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
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/o/{object}', name: 'record_', requirements: ['object' => '[a-z][a-z0-9_]*'])]
final class RecordController extends AbstractController
{
    private const int LOOKUP_LIMIT = 20;
    // The gridview relation filter loads its options once, without a search term.
    private const int FILTER_OPTIONS_LIMIT = 200;

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordManagerInterface $records,
        private readonly RecordFinderInterface $finder,
        private readonly RecordFormFactory $forms,
        private readonly RecordGridFactory $grids,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $object): Response
    {
        $definition = $this->objectFor($object, RecordAction::Read);

        return $this->grids->create($definition)->renderGrid('record/index.html.twig', [
            'object' => $definition,
            'can_create' => $this->access->canRecords($definition->name, RecordAction::Create),
        ]);
    }

    /**
     * Downloads the rows of the current filters, search and sort, with the columns on screen.
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(string $object, Request $request, GridExporterRegistry $exporters): Response
    {
        $definition = $this->objectFor($object, RecordAction::Read);
        $format = $request->query->getString('format', 'csv');
        if (!$exporters->has($format)) {
            throw $this->createNotFoundException(\sprintf('Unknown export format "%s".', $format));
        }

        $grid = $this->grids->create($definition);
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
     */
    #[Route('/lookup', name: 'lookup', methods: ['GET'])]
    public function lookup(string $object, Request $request, RecordLookupInterface $lookup): JsonResponse
    {
        $definition = $this->objectFor($object, RecordAction::Read);
        $records = $lookup->search($definition->name, $request->query->getString('query'), self::LOOKUP_LIMIT);

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
                $this->addFlash('success', \sprintf('%s created.', $definition->labelSingular));

                return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
            } catch (RecordValidationFailed $failure) {
                $this->forms->addErrors($form, $failure);
            }
        }

        return $this->render('record/form.html.twig', ['object' => $definition, 'form' => $form, 'record' => null]);
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
                $this->addFlash('success', \sprintf('%s updated.', $definition->labelSingular));

                return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
            } catch (RecordValidationFailed $failure) {
                $this->forms->addErrors($form, $failure);
            }
        }

        return $this->render('record/form.html.twig', [
            'object' => $definition,
            'form' => $form,
            'record' => $record,
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
        foreach ($this->cascadeTargets($definition->name) as $target) {
            if (!$this->access->canRecords($target->name, RecordAction::Delete)) {
                $this->addFlash('error', \sprintf('Deleting this record also deletes %s, which you may not delete.', $target->labelPlural));

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
            $this->addFlash('success', \sprintf('%s deleted.', $definition->labelSingular));
        }

        return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
    }

    /**
     * Every object whose records a deletion may cascade to, through cascade relations,
     * transitively. Decided on the metadata, whether such records exist or not.
     *
     * @return array<string, ObjectDefinitionDto> keyed by object name
     */
    private function cascadeTargets(string $objectName): array
    {
        $targets = [];
        $pending = [$objectName];
        while (null !== $current = array_pop($pending)) {
            foreach ($this->metadata->incomingRelations($current) as $reference) {
                $source = $reference->object;
                if (OnDelete::Cascade === $reference->settings->onDelete && !isset($targets[$source->name])) {
                    $targets[$source->name] = $source;
                    $pending[] = $source->name;
                }
            }
        }
        unset($targets[$objectName]);

        return $targets;
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
            $data[$child->getName()] = $child->getData();
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
