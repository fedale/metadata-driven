<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Exception\FormDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\CompositeRecordManagerInterface;
use App\Module\Record\Contract\Exception\CompositeValidationFailed;
use App\Module\Record\UI\Form\CompositeFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The pages of the composite forms: one form writing a record and the records related to it.
 *
 * Opening a form needs the read permission on every object it touches; saving it, the
 * create permission (a new form) or the create, update and delete permissions (an existing
 * one) on every object, since sections add, change and remove records.
 */
#[Route('/f/{form}', name: 'composite_', requirements: ['form' => '[a-z][a-z0-9_]*'])]
final class CompositeFormController extends AbstractController
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly CompositeRecordManagerInterface $records,
        private readonly CompositeFormFactory $forms,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(string $form, Request $request): Response
    {
        $definition = $this->formOr404($form);
        $this->denyUnless($definition, [RecordAction::Create]);
        $composite = $this->forms->create($definition, null)->handleRequest($request);

        if ($composite->isSubmitted() && $composite->isValid() && null !== $id = $this->save($definition, null, $composite)) {
            $this->addFlash('success', \sprintf('%s saved.', $definition->label));

            return $this->redirectToRoute('composite_edit', ['form' => $definition->name, 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('record/composite.html.twig', ['definition' => $definition, 'form' => $composite, 'id' => null, 'can_save' => true]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $form, string $id, Request $request): Response
    {
        $definition = $this->formOr404($form);
        $canSave = $this->can($definition, [RecordAction::Create, RecordAction::Update, RecordAction::Delete]);
        $this->denyUnless($definition, $request->isMethod('POST') ? [RecordAction::Create, RecordAction::Update, RecordAction::Delete] : [RecordAction::Read]);
        $record = $this->records->read($definition->name, $id) ?? throw $this->createNotFoundException(\sprintf('Record "%s" of object "%s" does not exist.', $id, $definition->root->object));
        $composite = $this->forms->create($definition, $record, readOnly: !$canSave)->handleRequest($request);

        if ($composite->isSubmitted() && $composite->isValid() && null !== $this->save($definition, $id, $composite)) {
            $this->addFlash('success', \sprintf('%s saved.', $definition->label));

            return $this->redirectToRoute('composite_edit', ['form' => $definition->name, 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('record/composite.html.twig', ['definition' => $definition, 'form' => $composite, 'id' => $id, 'can_save' => $canSave]);
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
     * @param list<RecordAction> $actions
     */
    private function denyUnless(FormDefinitionDto $definition, array $actions): void
    {
        if (!$this->can($definition, $actions)) {
            throw $this->createAccessDeniedException(\sprintf('No permission to %s the records of form "%s".', implode(', ', array_map(static fn (RecordAction $action): string => $action->value, $actions)), $definition->name));
        }
    }
}
