<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Admin\UI\Form\LifecycleForms;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renaming, archiving and restoring objects and fields. Each action answers with a redirect
 * and a flash message: the success with what was rewritten, or the refusal with its reasons
 * (the uses keeping something from being archived, a name taken...). Purging stays on the
 * command line (metadata:purge), where it asks to type the name back.
 */
#[Route('/admin', name: 'admin_')]
final class AdminLifecycleController extends AbstractController
{
    public function __construct(
        private readonly MetadataManagerInterface $manager,
        private readonly LifecycleForms $forms,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    #[Route('/objects/{object}/rename', name: 'object_rename', methods: ['POST'])]
    public function renameObject(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->forms->rename('', $object, '')->handleRequest($request);
        $name = $this->submittedName($form);
        if (null !== $name && $this->run(fn (): array => $this->manager->renameObject($object, $name), \sprintf('Object "%s" is now "%s", with its table.', $object, $name))) {
            return $this->redirectToRoute('admin_object_show', ['object' => $name], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_object_show', ['object' => $object], Response::HTTP_SEE_OTHER);
    }

    #[Route('/objects/{object}/archive', name: 'object_archive', methods: ['POST'])]
    public function archiveObject(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        if ($this->submitted('archive', $request) && $this->run(fn () => $this->manager->archiveObject($object), \sprintf('Object "%s" is archived: out of the application, its table and records kept.', $object))) {
            return $this->redirectToRoute('admin_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_object_show', ['object' => $object], Response::HTTP_SEE_OTHER);
    }

    #[Route('/archived/{object}/restore', name: 'object_restore', methods: ['POST'])]
    public function restoreObject(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        if ($this->submitted('restore', $request) && $this->run(fn () => $this->manager->restoreObject($object), \sprintf('Object "%s" is back, with its records.', $object))) {
            return $this->redirectToRoute('admin_object_show', ['object' => $object], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/objects/{object}/fields/{field}/rename', name: 'field_rename', methods: ['POST'])]
    public function renameField(string $object, string $field, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->forms->rename('', $field, '')->handleRequest($request);
        $name = $this->submittedName($form);
        if (null !== $name && $this->run(fn (): array => $this->manager->renameField($object, $field, $name), \sprintf('Field "%s" is now "%s", with its column.', $field, $name))) {
            return $this->redirectToRoute('admin_field_edit', ['object' => $object, 'field' => $name], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_field_edit', ['object' => $object, 'field' => $field], Response::HTTP_SEE_OTHER);
    }

    #[Route('/objects/{object}/fields/{field}/archive', name: 'field_archive', methods: ['POST'])]
    public function archiveField(string $object, string $field, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        if ($this->submitted('archive', $request) && $this->run(fn () => $this->manager->archiveField($object, $field), \sprintf('Field "%s" is archived: out of the forms and grids, its column and values kept.', $field))) {
            return $this->redirectToRoute('admin_object_show', ['object' => $object], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_field_edit', ['object' => $object, 'field' => $field], Response::HTTP_SEE_OTHER);
    }

    #[Route('/objects/{object}/archived-fields/{field}/restore', name: 'field_restore', methods: ['POST'])]
    public function restoreField(string $object, string $field, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        if ($this->submitted('restore', $request)) {
            $this->run(fn () => $this->manager->restoreField($object, $field), \sprintf('Field "%s" is back, with its values.', $field));
        }

        return $this->redirectToRoute('admin_object_show', ['object' => $object], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function submittedName(FormInterface $form): ?string
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The form expired: try again.');

            return null;
        }
        $name = $form->get('name')->getData();

        return \is_string($name) ? trim($name) : '';
    }

    private function submitted(string $name, Request $request): bool
    {
        $form = $this->forms->button($name, '')->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return true;
        }
        $this->addFlash('error', 'The form expired: try again.');

        return false;
    }

    /**
     * Runs a change and tells how it went. A rename lists the definitions written by hand that
     * it rewrote: their YAML lost its comments.
     *
     * @param callable(): mixed $change
     */
    private function run(callable $change, string $success): bool
    {
        try {
            $rewritten = $change();
        } catch (MetadataInputInvalid $e) {
            $this->addFlash('error', implode(' ', array_merge(...array_values($e->errors))));

            return false;
        } catch (MetadataException $e) {
            $this->addFlash('error', $e->getMessage());

            return false;
        }
        if (\is_array($rewritten) && [] !== $rewritten) {
            $success .= ' Rewritten to name the new one: '.implode(', ', array_filter($rewritten, is_string(...))).' (a YAML definition rewritten loses its comments).';
        }
        $this->addFlash('success', $success);

        return true;
    }

    private function denyUnlessAdministrator(): void
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the objects.');
        }
    }
}
