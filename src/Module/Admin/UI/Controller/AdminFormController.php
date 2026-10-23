<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Exception\FormDefinitionNotFound;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The composite forms of the no-code builder: each one is written in YAML, checked against
 * the objects when it is saved.
 */
#[Route('/admin/forms', name: 'admin_form_')]
final class AdminFormController extends AbstractController
{
    private const string NAME_HELP = 'Lowercase letters, digits and single underscores, starting with a letter. The form opens at /f/<name>/new.';
    private const string EXAMPLE = <<<'YAML'
        label: User registration
        object: account
        fields: [username, email, password]     # optional: every field when omitted
        service_fields:                         # optional: checked, never stored
          - { name: terms, type: boolean, label: I accept the terms, required: true }
        sections:
          - name: profile
            label: Profile
            object: profile
            via: account                        # relation field of profile pointing at account
            sections:
              - { name: phones, label: Phones, object: phone, via: profile, mode: many, min: 1, inline: true }
        YAML;

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly MetadataManagerInterface $manager,
        private readonly FormFactoryInterface $forms,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAdministrator();

        return $this->render('admin/forms.html.twig', ['forms' => $this->metadata->allForms()]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->definitionForm(null)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->text($form, 'name');
            if ($this->metadata->hasForm($name)) {
                $form->get('name')->addError(new FormError(\sprintf('A form named "%s" already exists.', $name)));
            } elseif ($this->save($form, $name)) {
                $this->addFlash('success', \sprintf('Form "%s" created.', $name));

                return $this->redirectToRoute('admin_form_edit', ['form' => $name], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('admin/form_edit.html.twig', ['definition' => null, 'form' => $form, 'objects' => $this->metadata->allObjects()]);
    }

    #[Route('/{form}', name: 'edit', requirements: ['form' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    public function edit(string $form, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->formOr404($form);
        $editor = $this->definitionForm($definition)->handleRequest($request);

        if ($editor->isSubmitted() && $editor->isValid() && $this->save($editor, $definition->name)) {
            $this->addFlash('success', 'Form saved.');

            return $this->redirectToRoute('admin_form_edit', ['form' => $definition->name], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/form_edit.html.twig', [
            'definition' => $definition,
            'form' => $editor,
            'objects' => $this->metadata->allObjects(),
            'delete_form' => $this->deleteForm($definition)->createView(),
        ]);
    }

    #[Route('/{form}/delete', name: 'delete', requirements: ['form' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function delete(string $form, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->formOr404($form);
        if ($this->deleteForm($definition)->handleRequest($request)->isSubmitted()) {
            try {
                $this->manager->deleteForm($definition->name);
                $this->addFlash('success', \sprintf('Form "%s" deleted; its records stay.', $definition->name));
            } catch (MetadataException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_form_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function save(FormInterface $form, string $name): bool
    {
        try {
            $this->manager->saveForm($name, $this->text($form, 'definition'));

            return true;
        } catch (MetadataInputInvalid $e) {
            foreach ($e->errors as $input => $messages) {
                $target = $form->has($input) ? $form->get($input) : $form;
                foreach ($messages as $message) {
                    $target->addError(new FormError($message));
                }
            }
        } catch (MetadataException $e) {
            $form->addError(new FormError($e->getMessage()));
        }

        return false;
    }

    /**
     * The name is the identity of a form: set at creation only.
     *
     * @return FormInterface<mixed>
     */
    private function definitionForm(?FormDefinitionDto $definition): FormInterface
    {
        $builder = $this->forms->createNamedBuilder('form_definition', FormType::class, ['definition' => $definition->source ?? self::EXAMPLE]);
        if (null === $definition) {
            $builder->add('name', TextType::class, ['label' => 'Machine name', 'help' => self::NAME_HELP]);
        }

        return $builder
            ->add('definition', TextareaType::class, [
                'label' => 'Definition (YAML)',
                'attr' => ['rows' => 24, 'class' => 'code', 'spellcheck' => 'false'],
            ])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(FormDefinitionDto $definition): FormInterface
    {
        return $this->forms->createNamedBuilder('delete_form', FormType::class, null, [
            'action' => $this->generateUrl('admin_form_delete', ['form' => $definition->name]),
        ])->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function text(FormInterface $form, string $child): string
    {
        $value = $form->get($child)->getData();

        return \is_string($value) ? $value : '';
    }

    private function formOr404(string $name): FormDefinitionDto
    {
        try {
            return $this->metadata->getForm($name);
        } catch (FormDefinitionNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function denyUnlessAdministrator(): void
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the forms.');
        }
    }
}
