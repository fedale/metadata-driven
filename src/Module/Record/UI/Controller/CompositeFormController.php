<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Exception\FormDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\CascadeTargets;
use App\Module\Record\Contract\CompositeRecordManagerInterface;
use App\Module\Record\Contract\Exception\CompositeValidationFailed;
use App\Module\Record\UI\Form\CompositeFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pages of the composite forms: one form writing a record and the records related to it.
 *
 * Opening a form needs the read permission on every object it touches; saving it, the
 * create permission (a new form) or the create, update and delete permissions (an existing
 * one) on every object, since sections add, change and remove records. Removing a row may
 * cascade to the records of other objects: saving an existing form also needs the delete
 * permission on each of them.
 */
#[Route('/f/{form}', name: 'composite_', requirements: ['form' => '[a-z][a-z0-9_]*'])]
final class CompositeFormController extends AbstractController
{
    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly CompositeRecordManagerInterface $records,
        private readonly CompositeFormFactory $forms,
        private readonly AccessCheckerInterface $access,
        private readonly CascadeTargets $cascadeTargets,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(string $form, Request $request): Response
    {
        $definition = $this->formOr404($form);
        $this->denyUnless($definition, [RecordAction::Create]);
        $composite = $this->forms->create($definition, null)->handleRequest($request);

        if ($composite->isSubmitted() && $composite->isValid() && null !== $id = $this->save($definition, null, $composite)) {
            $this->addFlash('success', $this->translator->trans('flash.saved', ['%form%' => $definition->label]));

            // "Save and new" (the `_action` of its button) opens an empty form again.
            if ('save_new' === $request->request->get('_action')) {
                return $this->redirectToRoute('composite_new', ['form' => $definition->name], Response::HTTP_SEE_OTHER);
            }

            return $this->redirectToRoute('composite_edit', ['form' => $definition->name, 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('record/composite.html.twig', [...$this->page($definition), 'form' => $composite, 'id' => null, 'can_save' => true]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $form, string $id, Request $request): Response
    {
        $definition = $this->formOr404($form);
        $canSave = $this->can($definition, [RecordAction::Create, RecordAction::Update, RecordAction::Delete]) && $this->canDeleteCascades($definition);
        $this->denyUnless($definition, $request->isMethod('POST') ? [RecordAction::Create, RecordAction::Update, RecordAction::Delete] : [RecordAction::Read]);
        if ($request->isMethod('POST') && !$this->canDeleteCascades($definition)) {
            throw $this->createAccessDeniedException(\sprintf('Saving form "%s" may delete records you may not delete.', $definition->name));
        }
        $record = $this->records->read($definition->name, $id) ?? throw $this->createNotFoundException(\sprintf('Record "%s" of object "%s" does not exist.', $id, $definition->root->object));
        $composite = $this->forms->create($definition, $record, readOnly: !$canSave)->handleRequest($request);

        if ($composite->isSubmitted() && $composite->isValid() && null !== $this->save($definition, $id, $composite)) {
            $this->addFlash('success', $this->translator->trans('flash.saved', ['%form%' => $definition->label]));

            return $this->redirectToRoute('composite_edit', ['form' => $definition->name, 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('record/composite.html.twig', [...$this->page($definition), 'form' => $composite, 'id' => $id, 'can_save' => $canSave]);
    }

    /**
     * What the page shows around the form: the object of its root (the title of its first card,
     * the way back to its records).
     *
     * @return array{definition: FormDefinitionDto, object: ObjectDefinitionDto, can_list: bool}
     */
    private function page(FormDefinitionDto $definition): array
    {
        return [
            'definition' => $definition,
            'object' => $this->metadata->getObject($definition->root->object),
            'can_list' => $this->access->canRecords($definition->root->object, RecordAction::Read),
        ];
    }

    /**
     * @param FormInterface<array<string, mixed>> $composite
     *
     * @return string|null the id of the root record; null when the input was refused (errors added)
     */
    private function save(FormDefinitionDto $definition, ?string $id, FormInterface $composite): ?string
    {
        try {
            return $this->records->save($definition->name, $id, $this->forms->input($definition->root, $composite->getData()));
        } catch (CompositeValidationFailed $failure) {
            $this->forms->addErrors($composite, $failure);

            return null;
        }
    }

    private function formOr404(string $name): FormDefinitionDto
    {
        try {
            return $this->metadata->getForm($name);
        } catch (FormDefinitionNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    /**
     * @param list<RecordAction> $actions
     */
    private function can(FormDefinitionDto $definition, array $actions): bool
    {
        foreach ($definition->root->objects() as $object) {
            foreach ($actions as $action) {
                if (!$this->access->canRecords($object, $action)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The rows a form removes are deleted with their delete rules: every object a cascade may
     * reach needs the delete permission too, as on a record's own page.
     */
    private function canDeleteCascades(FormDefinitionDto $definition): bool
    {
        foreach (self::rowObjects($definition->root) as $object) {
            foreach ($this->cascadeTargets->of($object) as $target) {
                if (!$this->access->canRecords($target->name, RecordAction::Delete)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The objects whose records the form may delete: those of its one-to-many sections, where
     * a row can be removed. The root and the one-to-one sections are never deleted.
     *
     * @return list<string>
     */
    private static function rowObjects(FormNodeDto $node): array
    {
        $objects = [];
        foreach ($node->sections as $section) {
            if (FormNodeMode::Many === $section->mode) {
                $objects[] = $section->object;
            }
            $objects = [...$objects, ...self::rowObjects($section)];
        }

        return array_values(array_unique($objects));
    }

    /**
     * @param list<RecordAction> $actions
     */
    private function denyUnless(FormDefinitionDto $definition, array $actions): void
    {
        if (!$this->can($definition, $actions)) {
            throw $this->createAccessDeniedException(\sprintf('No permission to %s the records of form "%s".', implode(', ', array_map(static fn (RecordAction $action): string => $action->value, $actions)), $definition->name));
        }
    }
}
