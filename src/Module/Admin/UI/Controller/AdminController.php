<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Admin\UI\Form\FieldSettingsForm;
use App\Module\Admin\UI\Form\LifecycleForms;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\UnknownFieldType;
use App\Module\FieldType\Contract\FieldTypeInterface;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
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
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The no-code builder: objects, fields, labels, search, views and the state of the tables.
 *
 * Every change goes through MetadataManagerInterface, which applies the matching DDL in the
 * same transaction. Open to everyone until access control arrives.
 *
 * Labels, help texts and placeholders are keys of translations/messages.*.yaml, translated by
 * the form theme; texts put together here (a flash, a help listing values) are translated here.
 */
#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    private const array TYPE_ORDER = ['text', 'number', 'date', 'boolean', 'select', 'email', 'tel', 'url', 'password', 'file', 'relation'];
    private const string GRID_VIEW_EXAMPLE = <<<'YAML'
        columns:
          - title
          - { field: summary, renderer: truncate, options: { length: 60 } }
          - { name: author, label: Author, template: '{firstname} {lastname}' }
          - { field: created, hidden: true }
        sort: { title: asc }
        page_size: 50
        YAML;

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly MetadataManagerInterface $manager,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly SearchHandlerCatalogInterface $searchHandlers,
        private readonly SchemaSynchronizerInterface $schema,
        private readonly FieldSettingsForm $settingsForm,
        private readonly FormFactoryInterface $forms,
        private readonly AccessCheckerInterface $access,
        private readonly LifecycleForms $lifecycle,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAdministrator();

        $archived = [];
        foreach ($this->metadata->archivedObjects() as $name => $object) {
            $archived[$name] = ['object' => $object, 'restore_form' => $this->lifecycle->button('restore', $this->generateUrl('admin_object_restore', ['object' => $name]))->createView()];
        }

        return $this->render('admin/index.html.twig', ['objects' => $this->metadata->allObjects(), 'archived' => $archived]);
    }

    #[Route('/objects/new', name: 'object_new', methods: ['GET', 'POST'])]
    public function newObject(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->forms->createNamedBuilder('object')
            ->add('name', TextType::class, ['label' => 'admin.machine_name', 'help' => 'admin.object.name_help'])
            ->add('labelSingular', TextType::class, ['label' => 'admin.object.singular_label'])
            ->add('labelPlural', TextType::class, ['label' => 'admin.object.plural_label'])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->text($form, 'name');
            $done = $this->apply($form, fn () => $this->manager->createObject($name, $this->text($form, 'labelSingular'), $this->text($form, 'labelPlural')));
            if ($done) {
                $this->addFlash('success', $this->translator->trans('admin.object.created', ['%name%' => $name]));

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
            'incoming' => [...$this->metadata->incomingRelations($definition->name), ...$this->metadata->incomingManyToMany($definition->name)],
            'plan' => $this->schema->plan($definition->name),
            'labels_form' => $this->labelsForm($definition)->createView(),
            'label_field_form' => $this->labelFieldForm($definition)->createView(),
            'search_form' => $this->searchForm($definition)->createView(),
            'unique_together' => array_map(
                fn (array $set): array => [
                    'labels' => array_map(static fn (string $field): string => $definition->field($field)->label, $set),
                    'remove_form' => $this->lifecycle->button('unique_remove', $this->generateUrl('admin_object_unique_remove', ['object' => $definition->name, 'fields' => implode(',', $set)]))->createView(),
                ],
                $definition->uniqueTogether,
            ),
            'unique_form' => $this->uniqueTogetherForm($definition)->createView(),
            'sync_form' => $this->actionForm('sync', 'admin_object_sync', $definition)->createView(),
            'rename_form' => $this->lifecycle->rename($this->generateUrl('admin_object_rename', ['object' => $definition->name]), $definition->name, 'admin.object.rename_help')->createView(),
            'archive_form' => $this->lifecycle->button('archive', $this->generateUrl('admin_object_archive', ['object' => $definition->name]))->createView(),
            'archived_fields' => array_map(
                fn (FieldDefinitionDto $field): array => ['field' => $field, 'restore_form' => $this->lifecycle->button('restore', $this->generateUrl('admin_field_restore', ['object' => $definition->name, 'field' => $field->name]))->createView()],
                $definition->archivedFields,
            ),
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
                $this->translator->trans('admin.object.labels_saved'),
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
                $this->translator->trans('admin.object.record_label_saved'),
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
                $this->translator->trans('admin.object.search_saved'),
            );
        }

        return $this->backToObject($definition);
    }

    #[Route('/objects/{object}/unique', name: 'object_unique', methods: ['POST'])]
    public function addUniqueTogether(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->uniqueTogetherForm($definition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $fields = $form->get('fields')->getData();
            $this->applyAndFlash(
                fn () => $this->manager->addUniqueTogether($definition->name, \is_array($fields) ? array_values(array_filter($fields, is_string(...))) : []),
                $this->translator->trans('admin.object.unique_added'),
            );
        }

        return $this->backToObject($definition);
    }

    #[Route('/objects/{object}/unique/{fields}/remove', name: 'object_unique_remove', requirements: ['fields' => '[a-z0-9_]+(,[a-z0-9_]+)+'], methods: ['POST'])]
    public function removeUniqueTogether(string $object, string $fields, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->lifecycle->button('unique_remove', $this->generateUrl('admin_object_unique_remove', ['object' => $definition->name, 'fields' => $fields]))->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAndFlash(
                fn () => $this->manager->removeUniqueTogether($definition->name, explode(',', $fields)),
                $this->translator->trans('admin.object.unique_removed'),
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
            $this->addFlash('success', $plan->isEmpty()
                ? $this->translator->trans('admin.object.table_up_to_date')
                : $this->translator->trans('admin.object.table_synced', ['%count%' => \count($plan->statements)]));
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
            ->add('name', TextType::class, ['label' => 'admin.machine_name', 'help' => 'admin.field.name_help'])
            ->add('label', TextType::class, ['label' => 'admin.label'])
            ->add('required', CheckboxType::class, ['label' => 'admin.field.required', 'required' => false])
            ->add('unique', CheckboxType::class, ['label' => 'admin.field.unique', 'required' => false, 'help' => 'admin.field.unique_help']);
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
                $this->addFlash('success', $this->translator->trans('admin.field.created', ['%name%' => $name]));

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

        $form = $this->labelForm($fieldDefinition)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->apply($form, fn () => $this->manager->relabelField($definition->name, $field, $this->text($form, 'label')))) {
                $this->addFlash('success', $this->translator->trans('admin.field.saved'));

                return $this->backToObject($definition);
            }
        }

        return $this->fieldPage($definition, $fieldDefinition, $form, $this->fieldSettingsForm($definition, $fieldDefinition));
    }

    #[Route('/objects/{object}/fields/{field}/settings', name: 'field_settings', methods: ['POST'])]
    public function configureFieldSettings(string $object, string $field, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        if (!$definition->hasField($field)) {
            throw $this->createNotFoundException(\sprintf('Object "%s" has no field "%s".', $object, $field));
        }
        $fieldDefinition = $definition->field($field);
        $form = $this->fieldSettingsForm($definition, $fieldDefinition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()
            && $this->apply($form, fn () => $this->manager->configureFieldSettings($definition->name, $field, $this->settingsForm->dataForUpdate($form)))) {
            $this->addFlash('success', $this->translator->trans('admin.field.settings_saved'));

            return $this->redirectToRoute('admin_field_edit', ['object' => $definition->name, 'field' => $field], Response::HTTP_SEE_OTHER);
        }

        // Refused: the page again, with the errors next to the settings.
        return $this->fieldPage($definition, $fieldDefinition, $this->labelForm($fieldDefinition), $form);
    }

    /**
     * The page of a field: its label, its settings and its condition, each a form of its own.
     *
     * @param FormInterface<mixed> $labelForm
     * @param FormInterface<mixed> $settingsForm
     */
    private function fieldPage(ObjectDefinitionDto $object, FieldDefinitionDto $field, FormInterface $labelForm, FormInterface $settingsForm): Response
    {
        return $this->render('admin/field_edit.html.twig', [
            'object' => $object,
            'field' => $field,
            'type' => $this->fieldTypes->get($field->type),
            'form' => $labelForm,
            'condition_form' => $this->conditionForm($object, $field)->createView(),
            // A FormInterface: render() answers 422 when it was submitted and refused.
            'settings_form' => $settingsForm,
            'rename_form' => $this->lifecycle->rename($this->generateUrl('admin_field_rename', ['object' => $object->name, 'field' => $field->name]), $field->name, 'admin.field.rename_help')->createView(),
            'archive_form' => $this->lifecycle->button('archive', $this->generateUrl('admin_field_archive', ['object' => $object->name, 'field' => $field->name]))->createView(),
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function labelForm(FieldDefinitionDto $field): FormInterface
    {
        return $this->forms->createNamedBuilder('field', FormType::class, ['label' => $field->label])
            ->add('label', TextType::class, ['label' => 'admin.label'])
            ->getForm();
    }

    #[Route('/objects/{object}/fields/{field}/condition', name: 'field_condition', methods: ['POST'])]
    public function configureFieldCondition(string $object, string $field, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        if (!$definition->hasField($field)) {
            throw $this->createNotFoundException(\sprintf('Object "%s" has no field "%s".', $object, $field));
        }
        $form = $this->conditionForm($definition, $definition->field($field))->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $when = $form->get('when')->getData();
            $values = array_values(array_filter(array_map(trim(...), explode(',', $this->text($form, 'values'))), static fn (string $value): bool => '' !== $value));
            $this->applyAndFlash(
                fn () => $this->manager->configureFieldCondition($definition->name, $field, \is_string($when) ? $when : null, $values),
                $this->translator->trans(\is_string($when) ? 'admin.field.condition_saved' : 'admin.field.always_applies'),
            );
        }

        return $this->redirectToRoute('admin_field_edit', ['object' => $definition->name, 'field' => $field], Response::HTTP_SEE_OTHER);
    }

    #[Route('/objects/{object}/views/new', name: 'view_new', methods: ['GET', 'POST'])]
    public function newView(string $object, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->viewForm($definition, null)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = $this->text($form, 'name');
            $kind = $form->get('kind')->getData();
            if ($kind instanceof ViewKind && $this->saveView($form, $definition, $kind, $name)) {
                $this->addFlash('success', $this->translator->trans('admin.view.created', ['%name%' => $name]));

                return $this->backToObject($definition);
            }
        }

        return $this->render('admin/view_edit.html.twig', ['object' => $definition, 'view' => null, 'form' => $form]);
    }

    #[Route('/objects/{object}/views/{kind}/{view}', name: 'view_edit', requirements: ['kind' => new EnumRequirement(ViewKind::class)], methods: ['GET', 'POST'])]
    public function editView(string $object, ViewKind $kind, string $view, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $viewDefinition = $this->viewOr404($definition, $kind, $view);
        $form = $this->viewForm($definition, $viewDefinition)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveView($form, $definition, $kind, $view)) {
            $this->addFlash('success', $this->translator->trans('admin.view.saved'));

            return $this->backToObject($definition);
        }

        return $this->render('admin/view_edit.html.twig', [
            'object' => $definition,
            'view' => $viewDefinition,
            'form' => $form,
            'delete_form' => $this->viewDeleteForm($definition, $viewDefinition)->createView(),
        ]);
    }

    #[Route('/objects/{object}/views/{kind}/{view}/delete', name: 'view_delete', requirements: ['kind' => new EnumRequirement(ViewKind::class)], methods: ['POST'])]
    public function deleteView(string $object, ViewKind $kind, string $view, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $definition = $this->objectOr404($object);
        $form = $this->viewDeleteForm($definition, $this->viewOr404($definition, $kind, $view))->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAndFlash(fn () => $this->manager->deleteView($definition->name, $kind, $view), $this->translator->trans('admin.view.deleted', ['%name%' => $view]));
        }

        return $this->backToObject($definition);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function saveView(FormInterface $form, ObjectDefinitionDto $object, ViewKind $kind, string $name): bool
    {
        return $this->apply($form, fn () => $this->manager->saveView(
            $object->name,
            $kind,
            $name,
            $this->text($form, 'template'),
            true === $form->get('isDefault')->getData(),
        ));
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
            ->add('labelSingular', TextType::class, ['label' => 'admin.object.singular_label'])
            ->add('labelPlural', TextType::class, ['label' => 'admin.object.plural_label'])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function labelFieldForm(ObjectDefinitionDto $object): FormInterface
    {
        $choices = [];
        foreach ($object->fields as $name => $field) {
            if ($field->storesColumn() && !$this->fieldTypes->get($field->type)->isWriteOnly()) {
                $choices[$field->label] = $name;
            }
        }

        return $this->forms->createNamedBuilder('label_field', FormType::class, ['field' => $object->labelField], [
            'action' => $this->generateUrl('admin_object_label_field', ['object' => $object->name]),
        ])
            ->add('field', ChoiceType::class, [
                'label' => 'admin.object.named_by',
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'admin.object.named_by_default',
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
            // A relation or a many-to-many field is searched on the labels of its records.
            if (null !== $field->target()) {
                $fields[$this->translator->trans('admin.object.searched_by_label', ['%field%' => $field->label, '%object%' => mb_strtolower($this->metadata->getObject($field->target())->labelSingular)])] = $name;
            } elseif ($field->storesColumn() && !$this->fieldTypes->get($field->type)->isWriteOnly()) {
                $fields[$field->label] = $name;
            }
        }

        return $this->forms->createNamedBuilder('search', FormType::class, [
            'handler' => $object->searchHandler,
            'fields' => $object->searchFields ?? [],
        ], ['action' => $this->generateUrl('admin_object_search', ['object' => $object->name])])
            ->add('handler', ChoiceType::class, [
                'label' => 'admin.object.search_handler',
                'choices' => array_combine($this->searchHandlers->names(), $this->searchHandlers->names()),
                'required' => false,
                'placeholder' => 'admin.object.search_handler_default',
            ])
            ->add('fields', ChoiceType::class, [
                'label' => 'admin.object.searched_fields',
                'help' => 'admin.object.searched_fields_help',
                'choices' => $fields,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->getForm();
    }

    /**
     * The fields that may be unique together: those a single field could be unique on.
     *
     * @return FormInterface<mixed>
     */
    private function uniqueTogetherForm(ObjectDefinitionDto $object): FormInterface
    {
        $fields = [];
        foreach ($object->fields as $name => $field) {
            if ($field->storesColumn() && $this->fieldTypes->get($field->type)->supportsUnique()) {
                $fields[$field->label] = $name;
            }
        }

        return $this->forms->createNamedBuilder('unique', FormType::class, null, [
            'action' => $this->generateUrl('admin_object_unique', ['object' => $object->name]),
        ])
            ->add('fields', ChoiceType::class, [
                'label' => 'admin.fields',
                'help' => 'admin.object.unique_fields_help',
                'choices' => $fields,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->getForm();
    }

    /**
     * The name and the kind are the identity of a view: set at creation only.
     *
     * @return FormInterface<mixed>
     */
    private function viewForm(ObjectDefinitionDto $object, ?ViewDefinitionDto $view): FormInterface
    {
        $tokens = implode(' ', array_map(static fn (string $name): string => '{'.$name.'}', array_keys($object->fields)));
        $builder = $this->forms->createNamedBuilder('view', FormType::class, [
            'name' => $view?->name,
            'kind' => $view->kind ?? ViewKind::Form,
            'template' => $view?->template,
            'isDefault' => $view?->isDefault,
        ]);
        if (null === $view) {
            $builder
                ->add('name', TextType::class, ['label' => 'admin.machine_name', 'help' => 'admin.name_help'])
                ->add('kind', EnumType::class, ['label' => 'admin.object.used_for', 'class' => ViewKind::class, 'choice_label' => static fn (ViewKind $kind): string => match ($kind) {
                    ViewKind::Form => 'admin.view.used_for_form',
                    ViewKind::Grid => 'admin.view.used_for_grid',
                }]);
        }

        return $builder
            ->add('template', TextareaType::class, [
                'label' => match ($view?->kind) {
                    ViewKind::Form => 'admin.view.layout',
                    ViewKind::Grid => 'admin.view.grid_definition',
                    null => 'admin.view.layout_or_grid',
                },
                'help' => $this->translator->trans('admin.view.fields_help', [
                    '%help%' => match ($view?->kind) {
                        ViewKind::Form => $this->translator->trans('admin.view.form_help'),
                        ViewKind::Grid => $this->translator->trans('admin.view.grid_help'),
                        null => $this->translator->trans('admin.view.both_help', [
                            '%form%' => $this->translator->trans('admin.view.form_help'),
                            '%grid%' => $this->translator->trans('admin.view.grid_help'),
                        ]),
                    },
                    '%tokens%' => $tokens,
                ]),
                'attr' => ['rows' => 16, 'class' => 'md-code-input', 'placeholder' => ViewKind::Grid === $view?->kind || null === $view ? self::GRID_VIEW_EXAMPLE : null],
            ])
            ->add('isDefault', CheckboxType::class, [
                'label' => 'admin.view.default_view',
                'required' => false,
                'help' => 'admin.view.default_help',
            ])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function viewDeleteForm(ObjectDefinitionDto $object, ViewDefinitionDto $view): FormInterface
    {
        return $this->forms->createNamedBuilder('delete_view', FormType::class, null, [
            'action' => $this->generateUrl('admin_view_delete', ['object' => $object->name, 'kind' => $view->kind->value, 'view' => $view->name]),
        ])->getForm();
    }

    /**
     * The settings of an existing field, generated from its type like those of a new field.
     *
     * @return FormInterface<mixed>
     */
    private function fieldSettingsForm(ObjectDefinitionDto $object, FieldDefinitionDto $field): FormInterface
    {
        $builder = $this->forms->createNamedBuilder('field_settings', FormType::class, null, [
            'action' => $this->generateUrl('admin_field_settings', ['object' => $object->name, 'field' => $field->name]),
        ]);
        $this->settingsForm->add($builder, $this->fieldTypes->get($field->type), $field->settings);

        return $builder->getForm();
    }

    /**
     * The deciding fields offered: the other choice and yes/no fields of the object, each with
     * the values it can hold in the help.
     *
     * @return FormInterface<mixed>
     */
    private function conditionForm(ObjectDefinitionDto $object, FieldDefinitionDto $field): FormInterface
    {
        $choices = [];
        $help = [];
        foreach ($object->fields as $name => $candidate) {
            if ($name === $field->name || null !== $candidate->condition) {
                continue;
            }
            $widget = $this->fieldTypes->get($candidate->type)->widget($candidate->settings);
            $values = match ($widget->kind) {
                WidgetKind::Choice => array_map(strval(...), array_keys($widget->choices())),
                WidgetKind::Checkbox => ['true', 'false'],
                default => null,
            };
            if (null !== $values) {
                $choices[$candidate->label] = $name;
                $help[] = \sprintf('%s: %s', $name, implode(', ', $values));
            }
        }

        return $this->forms->createNamedBuilder('condition', FormType::class, [
            'when' => $field->condition?->field,
            'values' => null === $field->condition ? '' : implode(', ', $field->condition->values),
        ], ['action' => $this->generateUrl('admin_field_condition', ['object' => $object->name, 'field' => $field->name])])
            ->add('when', ChoiceType::class, [
                'label' => 'admin.field.shown_when',
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'admin.field.always',
            ])
            ->add('values', TextType::class, [
                'label' => 'admin.field.is_one_of',
                'required' => false,
                'help' => [] === $help ? 'admin.field.no_deciding_field' : $this->translator->trans('admin.field.values_help', ['%values%' => implode(' · ', $help)]),
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

    private function viewOr404(ObjectDefinitionDto $object, ViewKind $kind, string $name): ViewDefinitionDto
    {
        return $object->view($kind, $name)
            ?? throw $this->createNotFoundException(\sprintf('Object "%s" has no %s view "%s".', $object->name, $kind->value, $name));
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
