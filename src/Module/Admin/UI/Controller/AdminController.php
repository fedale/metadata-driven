<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Admin\UI\Form\FieldSettingsForm;
use App\Module\FieldType\Contract\Exception\UnknownFieldType;
use App\Module\FieldType\Contract\FieldTypeInterface;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\SearchHandlerCatalogInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The no-code builder: objects, fields, labels, search and the state of the tables.
 *
 * Every change goes through MetadataManagerInterface, which applies the matching DDL in the
 * same transaction. Open to everyone until access control arrives.
 */
#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    private const array TYPE_ORDER = ['text', 'number', 'date', 'boolean', 'email', 'select', 'relation'];
    private const string NAME_HELP = 'Lowercase letters, digits and single underscores, starting with a letter. It cannot be changed later.';

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly MetadataManagerInterface $manager,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly SearchHandlerCatalogInterface $searchHandlers,
        private readonly SchemaSynchronizerInterface $schema,
        private readonly FieldSettingsForm $settingsForm,
        private readonly FormFactoryInterface $forms,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAdministrator();

        return $this->render('admin/index.html.twig', ['objects' => $this->metadata->allObjects()]);
    }

    #[Route('/objects/new', name: 'object_new', methods: ['GET', 'POST'])]
    public function newObject(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->forms->createNamedBuilder('object')
            ->add('name', TextType::class, ['label' => 'Machine name', 'help' => self::NAME_HELP.' The table is data.c_<name>.'])
            ->add('labelSingular', TextType::class, ['label' => 'Singular label'])
            ->add('labelPlural', TextType::class, ['label' => 'Plural label'])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->text($form, 'name');
            $done = $this->apply($form, fn () => $this->manager->createObject($name, $this->text($form, 'labelSingular'), $this->text($form, 'labelPlural')));
            if ($done) {
                $this->addFlash('success', \sprintf('Object "%s" created, with its table.', $name));

                return $this->redirectToRoute('admin_object_show', ['object' => $name], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('admin/object_new.html.twig', ['form' => $form]);
    }

    #[Route('/objects/{object}', name: 'object_show', methods: ['GET'])]
    public function showObject(string $object): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);

        return $this->render('admin/object_show.html.twig', [
            'object' => $definition,
            'types' => $this->fieldTypes->all(),
            'incoming' => $this->metadata->incomingRelations($definition->name),
            'plan' => $this->schema->plan($definition->name),
            'labels_form' => $this->labelsForm($definition)->createView(),
            'label_field_form' => $this->labelFieldForm($definition)->createView(),
            'search_form' => $this->searchForm($definition)->createView(),
            'sync_form' => $this->actionForm('sync', 'admin_object_sync', $definition)->createView(),
        ]);
    }

    #[Route('/objects/{object}/labels', name: 'object_labels', methods: ['POST'])]
    public function relabelObject(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->labelsForm($definition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAndFlash(
                fn () => $this->manager->relabelObject($definition->name, $this->text($form, 'labelSingular'), $this->text($form, 'labelPlural')),
                'Labels saved.',
            );
        }

        return $this->backToObject($definition);
    }

    #[Route('/objects/{object}/label-field', name: 'object_label_field', methods: ['POST'])]
    public function configureLabel(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->labelFieldForm($definition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $field = $form->get('field')->getData();
            $this->applyAndFlash(
                fn () => $this->manager->configureLabel($definition->name, \is_string($field) ? $field : null),
                'Record label saved.',
            );
        }

        return $this->backToObject($definition);
    }

    #[Route('/objects/{object}/search', name: 'object_search', methods: ['POST'])]
    public function configureSearch(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->searchForm($definition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $handler = $form->get('handler')->getData();
            $fields = $form->get('fields')->getData();
            $this->applyAndFlash(
                fn () => $this->manager->configureSearch(
                    $definition->name,
                    \is_string($handler) ? $handler : null,
                    // No field ticked means "the default fields".
                    \is_array($fields) && [] !== $fields ? array_values(array_filter($fields, is_string(...))) : null,
                ),
                'Search saved; the search indexes follow.',
            );
        }

        return $this->backToObject($definition);
    }

    /**
     * Applies the non-destructive changes the table is missing (destructive ones stay a
     * console decision: schema:sync --allow-destructive).
     */
    #[Route('/objects/{object}/sync', name: 'object_sync', methods: ['POST'])]
    public function synchronize(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->actionForm('sync', 'admin_object_sync', $definition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $plan = $this->schema->synchronize($definition->name);
            $this->addFlash('success', $plan->isEmpty() ? 'The table was already up to date.' : \sprintf('%d change(s) applied to the table.', \count($plan->statements)));
        }

        return $this->backToObject($definition);
    }

    #[Route('/objects/{object}/fields/new', name: 'field_type', methods: ['GET'])]
    public function chooseFieldType(string $object): Response
    {
        $this->denyUnlessAdministrator();
        // The common types first; any other (a type added later) after them.
        $types = $this->fieldTypes->all();
        $ordered = array_merge(array_flip(array_intersect(self::TYPE_ORDER, array_keys($types))), $types);

        return $this->render('admin/field_type.html.twig', [
            'object' => $this->objectOr404($object),
            'types' => $ordered,
        ]);
    }

    #[Route('/objects/{object}/fields/new/{type}', name: 'field_new', methods: ['GET', 'POST'])]
    public function newField(string $object, string $type, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $fieldType = $this->fieldTypeOr404($type);

        $builder = $this->forms->createNamedBuilder('field')
            ->add('name', TextType::class, ['label' => 'Machine name', 'help' => self::NAME_HELP.' It is also the column name.'])
            ->add('label', TextType::class, ['label' => 'Label'])
            ->add('required', CheckboxType::class, ['label' => 'Required', 'required' => false])
            ->add('unique', CheckboxType::class, ['label' => 'Unique', 'required' => false, 'help' => 'Backed by a unique index.']);
        $this->settingsForm->add($builder, $fieldType);
        $form = $builder->getForm()->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->text($form, 'name');
            $done = $this->apply($form, fn () => $this->manager->createField(
                $definition->name,
                $name,
                $fieldType->name(),
                $this->text($form, 'label'),
                true === $form->get('required')->getData(),
                true === $form->get('unique')->getData(),
                $this->settingsForm->data($form),
            ));
            if ($done) {
                $this->addFlash('success', \sprintf('Field "%s" created, with its column.', $name));

                return $this->backToObject($definition);
            }
        }

        return $this->render('admin/field_new.html.twig', ['object' => $definition, 'type' => $fieldType, 'form' => $form]);
    }

    #[Route('/objects/{object}/fields/{field}', name: 'field_edit', methods: ['GET', 'POST'])]
    public function editField(string $object, string $field, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        if (!$definition->hasField($field)) {
            throw $this->createNotFoundException(\sprintf('Object "%s" has no field "%s".', $object, $field));
        }
        $fieldDefinition = $definition->field($field);

        $form = $this->forms->createNamedBuilder('field', FormType::class, ['label' => $fieldDefinition->label])
            ->add('label', TextType::class, ['label' => 'Label'])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->apply($form, fn () => $this->manager->relabelField($definition->name, $field, $this->text($form, 'label')))) {
                $this->addFlash('success', 'Field saved.');

                return $this->backToObject($definition);
            }
        }

        return $this->render('admin/field_edit.html.twig', [
            'object' => $definition,
            'field' => $fieldDefinition,
            'type' => $this->fieldTypes->get($fieldDefinition->type),
            'form' => $form,
        ]);
    }

    /**
     * Runs a change; its errors become form errors (on the matching input when there is one).
     *
     * @param FormInterface<mixed> $form
     * @param callable(): mixed    $change
     */
    private function apply(FormInterface $form, callable $change): bool
    {
        try {
            $change();

            return true;
        } catch (MetadataInputInvalid $e) {
            foreach ($e->errors as $input => $messages) {
                $target = $form->has($input) ? $form->get($input) : $form;
                foreach ($messages as $message) {
                    $target->addError(new FormError($message));
                }
            }
        } catch (InvalidFieldSettings $e) {
            $target = $form->has('settings') ? $form->get('settings') : $form;
            foreach ($e->errors as $message) {
                $target->addError(new FormError($message));
            }
        } catch (MetadataException $e) {
            $form->addError(new FormError($e->getMessage()));
        }

        return false;
    }

    /**
     * @param callable(): mixed $change
     */
    private function applyAndFlash(callable $change, string $success): void
    {
        try {
            $change();
            $this->addFlash('success', $success);
        } catch (MetadataException $e) {
            $this->addFlash('error', $e->getMessage());
        }
    }

    /**
     * @return FormInterface<mixed>
     */
    private function labelsForm(ObjectDefinitionDto $object): FormInterface
    {
        return $this->forms->createNamedBuilder('labels', FormType::class, [
            'labelSingular' => $object->labelSingular,
            'labelPlural' => $object->labelPlural,
        ], ['action' => $this->generateUrl('admin_object_labels', ['object' => $object->name])])
            ->add('labelSingular', TextType::class, ['label' => 'Singular label'])
            ->add('labelPlural', TextType::class, ['label' => 'Plural label'])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function labelFieldForm(ObjectDefinitionDto $object): FormInterface
    {
        $choices = [];
        foreach ($object->fields as $name => $field) {
            $choices[$field->label] = $name;
        }

        return $this->forms->createNamedBuilder('label_field', FormType::class, ['field' => $object->labelField], [
            'action' => $this->generateUrl('admin_object_label_field', ['object' => $object->name]),
        ])
            ->add('field', ChoiceType::class, [
                'label' => 'Records are named by',
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'Default: the first text field',
            ])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function searchForm(ObjectDefinitionDto $object): FormInterface
    {
        $fields = [];
        foreach ($object->fields as $name => $field) {
            if (null === $field->relation()) {
                $fields[$field->label] = $name;
            }
        }

        return $this->forms->createNamedBuilder('search', FormType::class, [
            'handler' => $object->searchHandler,
            'fields' => $object->searchFields ?? [],
        ], ['action' => $this->generateUrl('admin_object_search', ['object' => $object->name])])
            ->add('handler', ChoiceType::class, [
                'label' => 'Search handler',
                'choices' => array_combine($this->searchHandlers->names(), $this->searchHandlers->names()),
                'required' => false,
                'placeholder' => 'Default: every word, in any field',
            ])
            ->add('fields', ChoiceType::class, [
                'label' => 'Searched fields',
                'help' => 'None ticked: every text field.',
                'choices' => $fields,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->getForm();
    }

    /**
     * A form with no fields, for a button that changes something (CSRF protected).
     *
     * @return FormInterface<mixed>
     */
    private function actionForm(string $name, string $route, ObjectDefinitionDto $object): FormInterface
    {
        return $this->forms->createNamedBuilder($name, FormType::class, null, [
            'action' => $this->generateUrl($route, ['object' => $object->name]),
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

    private function denyUnlessAdministrator(): void
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the objects.');
        }
    }

    private function objectOr404(string $name): ObjectDefinitionDto
    {
        try {
            return $this->metadata->getObject($name);
        } catch (ObjectDefinitionNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function fieldTypeOr404(string $name): FieldTypeInterface
    {
        try {
            return $this->fieldTypes->get($name);
        } catch (UnknownFieldType $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function backToObject(ObjectDefinitionDto $object): Response
    {
        return $this->redirectToRoute('admin_object_show', ['object' => $object->name], Response::HTTP_SEE_OTHER);
    }
}
