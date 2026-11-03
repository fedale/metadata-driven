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

/**
 * The no-code builder: objects, fields, labels, search, views and the state of the tables.
 *
 * Every change goes through MetadataManagerInterface, which applies the matching DDL in the
 * same transaction. Open to everyone until access control arrives.
 */
#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    private const array TYPE_ORDER = ['text', 'number', 'date', 'boolean', 'select', 'email', 'tel', 'url', 'password', 'file', 'relation'];
    private const string NAME_HELP = 'Lowercase letters, digits and single underscores, starting with a letter.';
    private const string RENAME_OBJECT_HELP = 'Lowercase letters, digits and single underscores. The table, the relations pointing here, the composite forms, the translations and the permissions follow; the links to the old pages stop working.';
    private const string RENAME_FIELD_HELP = 'Lowercase letters, digits and single underscores. The column, the views, the composite forms, the conditions, the cascades and the translations follow.';
    private const string FORM_VIEW_HELP = 'Put a {field} token where each field goes; the fields left out follow the layout. A {t:name} token shows a text of Admin > Translations in the user\'s language. Scripts, styles and event attributes are removed; class attributes are kept.';
    private const string GRID_VIEW_HELP = 'The columns in order: a field name, or a map with field, label, renderer (truncate, badge, date), options, hidden, sortable, filter; a virtual column takes a name and a template of {field} tokens. A label may hold {t:name} tokens (Admin > Translations). Optional: sort and page_size.';
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
            'incoming' => [...$this->metadata->incomingRelations($definition->name), ...$this->metadata->incomingManyToMany($definition->name)],
            'plan' => $this->schema->plan($definition->name),
            'labels_form' => $this->labelsForm($definition)->createView(),
            'label_field_form' => $this->labelFieldForm($definition)->createView(),
            'search_form' => $this->searchForm($definition)->createView(),
            'sync_form' => $this->actionForm('sync', 'admin_object_sync', $definition)->createView(),
            'rename_form' => $this->lifecycle->rename($this->generateUrl('admin_object_rename', ['object' => $definition->name]), $definition->name, self::RENAME_OBJECT_HELP)->createView(),
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

        $form = $this->labelForm($fieldDefinition)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->apply($form, fn () => $this->manager->relabelField($definition->name, $field, $this->text($form, 'label')))) {
                $this->addFlash('success', 'Field saved.');

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
            $this->addFlash('success', 'Settings saved.');

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
            'rename_form' => $this->lifecycle->rename($this->generateUrl('admin_field_rename', ['object' => $object->name, 'field' => $field->name]), $field->name, self::RENAME_FIELD_HELP)->createView(),
            'archive_form' => $this->lifecycle->button('archive', $this->generateUrl('admin_field_archive', ['object' => $object->name, 'field' => $field->name]))->createView(),
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function labelForm(FieldDefinitionDto $field): FormInterface
    {
        return $this->forms->createNamedBuilder('field', FormType::class, ['label' => $field->label])
            ->add('label', TextType::class, ['label' => 'Label'])
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
                \is_string($when) ? 'Condition saved.' : 'The field always applies.',
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
                $this->addFlash('success', \sprintf('View "%s" created.', $name));

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
            $this->addFlash('success', 'View saved.');

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
            $this->applyAndFlash(fn () => $this->manager->deleteView($definition->name, $kind, $view), \sprintf('View "%s" deleted.', $view));
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
            if ($field->storesColumn() && !$this->fieldTypes->get($field->type)->isWriteOnly()) {
                $choices[$field->label] = $name;
            }
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
            if (null === $field->relation() && $field->storesColumn() && !$this->fieldTypes->get($field->type)->isWriteOnly()) {
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
                ->add('name', TextType::class, ['label' => 'Machine name', 'help' => self::NAME_HELP])
                ->add('kind', EnumType::class, ['label' => 'Used for', 'class' => ViewKind::class, 'choice_label' => static fn (ViewKind $kind): string => match ($kind) {
                    ViewKind::Form => 'The record form',
                    ViewKind::Grid => 'The record grid',
                }]);
        }

        return $builder
            ->add('template', TextareaType::class, [
                'label' => match ($view?->kind) {
                    ViewKind::Form => 'Layout (HTML)',
                    ViewKind::Grid => 'Grid definition (YAML)',
                    null => 'Layout (HTML) or grid definition (YAML)',
                },
                'help' => match ($view?->kind) {
                    ViewKind::Form => self::FORM_VIEW_HELP,
                    ViewKind::Grid => self::GRID_VIEW_HELP,
                    null => 'Form: '.self::FORM_VIEW_HELP.' Grid: '.self::GRID_VIEW_HELP,
                }.' Fields: '.$tokens.'.',
                'attr' => ['rows' => 16, 'class' => 'md-code-input', 'placeholder' => ViewKind::Grid === $view?->kind || null === $view ? self::GRID_VIEW_EXAMPLE : null],
            ])
            ->add('isDefault', CheckboxType::class, [
                'label' => 'Default view',
                'required' => false,
                'help' => 'Used when the page names no view. The first view of a kind is the default; to change it, mark another view.',
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
                'label' => 'Shown when',
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'Always',
            ])
            ->add('values', TextType::class, [
                'label' => 'is one of',
                'required' => false,
                'help' => [] === $help ? 'The object has no choice or yes/no field to decide.' : 'Comma separated. '.implode(' · ', $help),
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
