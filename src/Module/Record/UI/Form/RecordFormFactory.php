<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ServiceFieldDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\RecordLabels;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordLookupInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the record form from the field definitions at runtime.
 *
 * The form only converts input: it carries no constraints, validation is done once
 * by the Record module with the rules stored in the metadata.
 */
final class RecordFormFactory
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly RecordLookupInterface $lookup,
        private readonly UrlGeneratorInterface $urls,
        private readonly FileStorageInterface $files,
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * @param bool $readOnly every control disabled: the user may read the record, not change it
     *
     * @return FormInterface<mixed>
     */
    public function create(ObjectDefinitionDto $object, ?RecordOutput $record = null, bool $readOnly = false): FormInterface
    {
        $builder = $this->formFactory->createNamedBuilder('record', FormType::class, $record->values ?? $this->defaults($object), ['disabled' => $readOnly]);
        $this->addControls($builder, $object, array_keys($object->fields), null === $record, $record->values ?? []);

        return $builder->getForm();
    }

    /**
     * Adds the controls of some fields of an object to a form, in the given order.
     *
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     * @param list<string>            $fieldNames
     * @param bool                    $isNew        the record does not exist yet (a password is then required)
     * @param array<string, mixed>    $current      the current values, to offer the related records chosen
     * @param list<string>            $required     fields required here although their object does not require them
     * @param bool                    $placeholders the label as the placeholder of each input, for labels kept off screen
     */
    public function addControls(FormBuilderInterface $builder, ObjectDefinitionDto $object, array $fieldNames, bool $isNew, array $current, array $required = [], bool $placeholders = false): void
    {
        $relations = [];
        foreach ($fieldNames as $name) {
            $field = $object->field($name);
            $requiredHere = \in_array($name, $required, true);
            if ($requiredHere) {
                $field = new FieldDefinitionDto($field->id, $field->name, $field->columnName, $field->type, $field->label, true, $field->isUnique, $field->settings, $field->position, $field->isSystem);
            }
            $widget = $this->fieldTypes->get($field->type)->widget($field->settings);
            $dependsOn = $field->relation()?->dependsOn;
            [$type, $options] = $this->control($field, $widget, $current[$name] ?? null, $isNew, null === $dependsOn ? null : ($current[$dependsOn] ?? null));
            // A checkbox required by the form must be checked (by its object, only set).
            if ($requiredHere && WidgetKind::Checkbox === $widget->kind) {
                $options['required'] = true;
            }
            if ($placeholders && !\in_array($widget->kind, [WidgetKind::Choice, WidgetKind::Relation, WidgetKind::ManyToMany, WidgetKind::Checkbox, WidgetKind::File], true)) {
                $options['attr'] = (\is_array($options['attr'] ?? null) ? $options['attr'] : []) + ['placeholder' => $field->label.($field->isRequired ? ' *' : '')];
            }
            $options += ['label' => $field->label];
            $options['row_attr'] = $this->rowAttr($field, $options['row_attr'] ?? []);
            // Links through a junction object are shown, not edited: they are its records.
            $through = $field->manyToMany()?->through;
            if (null !== $through) {
                $builder->add($name, $type, ['disabled' => true, 'required' => false, 'help' => 'form.through_links', 'help_translation_parameters' => ['%object%' => $this->metadata->getObject($through)->labelPlural]] + $options);
                continue;
            }
            $builder->add($name, $type, $options);
            if (\in_array($widget->kind, [WidgetKind::Relation, WidgetKind::ManyToMany], true)) {
                $relations[$name] = [$options, $this->target($widget), WidgetKind::ManyToMany === $widget->kind];
            }
        }
        if ([] === $relations) {
            return;
        }

        // A relation (or a many-to-many) offers only the current records until the user
        // searches: the submitted records become choices again, so that the form accepts them.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (PreSubmitEvent $event) use ($relations): void {
            $data = $event->getData();
            foreach ($relations as $name => [$options, $target, $multiple]) {
                $submitted = \is_array($data) ? ($data[$name] ?? null) : null;
                $ids = array_values(array_filter(\is_array($submitted) ? $submitted : [$submitted], static fn (mixed $id): bool => \is_string($id) && '' !== $id));
                $options = $multiple
                    ? $this->linkChoices($target, $ids) + $options
                    : ['choices' => [] !== $ids ? array_flip($this->lookup->labels($target, $ids)) : []] + $options;
                $event->getForm()->add($name, ChoiceType::class, $options);
            }
        });
    }

    /**
     * Every row names its field; the row of a field with a condition is shown and hidden by
     * the gridview-conditional controller of fedale/gridview-bundle, as the deciding field
     * changes. The server empties the field when it does not apply, whatever the browser did.
     *
     * @return array<string, mixed>
     */
    private function rowAttr(FieldDefinitionDto $field, mixed $rowAttr): array
    {
        /** @var array<string, mixed> $rowAttr row_attr is a map of HTML attributes */
        $rowAttr = (\is_array($rowAttr) ? $rowAttr : []) + ['data-gv-field' => $field->name];
        $controllers = [];
        if (null !== $field->condition) {
            $controllers[] = 'gridview-conditional';
            $rowAttr['data-gridview-conditional-field-value'] = $field->condition->field;
            $rowAttr['data-gridview-conditional-values-value'] = json_encode($field->condition->values, \JSON_THROW_ON_ERROR);
        }
        // A cascade: when the field it depends on changes, its choice is emptied and its lookup
        // URL follows (gridview-dependent-select of fedale/gridview-bundle).
        $relation = $field->relation();
        if (null !== $relation?->dependsOn && null !== $relation->dependsVia) {
            $controllers[] = 'gridview-dependent-select';
            $rowAttr['data-gridview-dependent-select-field-value'] = $relation->dependsOn;
            $rowAttr['data-gridview-dependent-select-url-value'] = $this->urls->generate('record_lookup', ['object' => $relation->target]);
            $rowAttr['data-gridview-dependent-select-param-value'] = \sprintf('filter[%s]', $relation->dependsVia);
        }
        if ([] !== $controllers) {
            $rowAttr['data-controller'] = implode(' ', $controllers);
        }

        return $rowAttr;
    }

    /**
     * The lookup URL of a relation; for a cascade, filtered by the record chosen in the field it
     * depends on (an empty filter lists nothing until one is chosen).
     */
    private function lookupUrl(FieldDefinitionDto $field, Widget $widget, mixed $parentValue): string
    {
        $relation = $field->relation();
        $parameters = ['object' => $this->target($widget)];
        if (null !== $relation?->dependsVia) {
            $parameters['filter'] = [$relation->dependsVia => \is_string($parentValue) ? $parentValue : ''];
        }

        return $this->urls->generate('record_lookup', $parameters);
    }

    /**
     * Adds the control of a field that belongs to no record (a service field of a composite
     * form). A required checkbox must be checked, as "I accept the terms" asks.
     *
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     */
    public function addServiceControl(FormBuilderInterface $builder, ServiceFieldDto $field): void
    {
        $definition = new FieldDefinitionDto('', $field->name, $field->name, $field->type, $field->label, $field->isRequired, false, $field->settings, 0, false);
        $widget = $this->fieldTypes->get($field->type)->widget($field->settings);
        [$type, $options] = $this->control($definition, $widget, null, true);
        if (WidgetKind::Checkbox === $widget->kind) {
            $options['required'] = $field->isRequired;
        }
        $builder->add($field->name, $type, $options + ['label' => $field->label]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    public function addErrors(FormInterface $form, RecordValidationFailed $failure): void
    {
        foreach ($failure->errors as $field => $messages) {
            $target = RecordValidationFailed::GLOBAL_ERRORS !== $field && $form->has($field) ? $form->get($field) : $form;
            foreach ($messages as $message) {
                $target->addError(new FormError($message));
            }
        }
    }

    /**
     * The values a new record starts with: the default value of each field that has one.
     *
     * @return array<string, mixed>
     */
    public function defaults(ObjectDefinitionDto $object): array
    {
        $values = [];
        foreach ($object->fields as $name => $field) {
            $values[$name] = $this->fieldTypes->get($field->type)->defaultValue($field->settings);
        }

        return array_filter($values, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @return array{class-string<FormType|TextType|TextareaType|IntegerType|NumberType|CheckboxType|DateType|DateTimeType|EmailType|ChoiceType|PasswordType|RepeatedType|UrlType|TelType|RecordFileType|RecordFileListType>, array<string, mixed>}
     */
    private function control(FieldDefinitionDto $field, Widget $widget, mixed $value, bool $isNew, mixed $parentValue = null): array
    {
        $required = ['required' => $field->isRequired];
        $maxLength = \is_int($widget->options['maxLength'] ?? null) ? ['attr' => ['maxlength' => $widget->options['maxLength']]] : [];
        $bounds = ['attr' => array_filter(['min' => $widget->options['min'] ?? null, 'max' => $widget->options['max'] ?? null], is_int(...))];

        return match ($widget->kind) {
            WidgetKind::Text => [TextType::class, $required + $maxLength],
            WidgetKind::Textarea => [TextareaType::class, $required + $maxLength],
            WidgetKind::Email => [EmailType::class, $required + $maxLength],
            WidgetKind::Url => [UrlType::class, $required + $maxLength + ['default_protocol' => null]],
            WidgetKind::Tel => [TelType::class, $required + $maxLength],
            WidgetKind::Password => $this->password($field, $widget, $isNew),
            WidgetKind::Integer => [IntegerType::class, $required + $bounds],
            // No "scale": the form would silently round extra decimals instead of reporting them.
            WidgetKind::Decimal => [NumberType::class, $required + ['input' => 'string', 'html5' => true, 'attr' => ['step' => $this->step($widget)] + $bounds['attr']]],
            // A required checkbox would have to be checked: "required" only means "not null" here.
            WidgetKind::Checkbox => [CheckboxType::class, ['required' => false] + ('switch' === ($widget->options['display'] ?? null)
                ? ['attr' => ['class' => 'gv-switch', 'role' => 'switch']]
                : [])],
            WidgetKind::Date => [DateType::class, $required + ['widget' => 'single_text', 'input' => 'datetime_immutable']],
            WidgetKind::DateTime => [DateTimeType::class, $required + ['widget' => 'single_text', 'input' => 'datetime_immutable']],
            WidgetKind::Choice => [ChoiceType::class, $required + ['choices' => array_flip($widget->choices())] + ('radio' === ($widget->options['display'] ?? null)
                // No placeholder: an empty radio group already means "no value".
                ? ['expanded' => true, 'placeholder' => false, 'attr' => ['class' => 'gv-segmented']]
                : ['placeholder' => $field->isRequired ? 'form.choose_option' : '—'])],
            WidgetKind::Relation => [ChoiceType::class, $required + [
                'choices' => \is_string($value) ? array_flip($this->lookup->labels($this->target($widget), [$value])) : [],
                'placeholder' => $field->isRequired ? 'form.choose_record' : '—',
                'autocomplete' => true,
                'autocomplete_url' => $this->lookupUrl($field, $widget, $parentValue),
                // A cascade lists the few records of the chosen parent as soon as it opens.
                'min_characters' => null === $field->relation()?->dependsOn ? 1 : 0,
            ]],
            // The linked records, in the order they were linked; any number of them.
            WidgetKind::ManyToMany => [ChoiceType::class, $required + $this->linkChoices($this->target($widget), \is_array($value) ? array_values(array_filter($value, \is_string(...))) : []) + [
                'multiple' => true,
                'autocomplete' => true,
                'autocomplete_url' => $this->lookupUrl($field, $widget, null),
                'min_characters' => 1,
            ]],
            WidgetKind::File => $this->file($field, $widget, $value),
        };
    }

    /**
     * The current file is looked up here, from the stored value: the form never takes a file id
     * from the request. The same for the current files of a file list, in their order.
     *
     * @return array{class-string<RecordFileType|RecordFileListType>, array<string, mixed>}
     */
    private function file(FieldDefinitionDto $field, Widget $widget, mixed $value): array
    {
        $list = $field->fileList();
        if (null !== $list) {
            $ids = \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
            $found = [] !== $ids ? $this->files->findMany($ids) : [];
            $current = array_values(array_filter(array_map(static fn (string $id) => $found[strtolower($id)] ?? null, $ids)));

            return [RecordFileListType::class, [
                'required' => $field->isRequired,
                'current' => $current,
                'accept' => \is_string($widget->options['accept'] ?? null) ? $widget->options['accept'] : '',
                'help' => null !== $list->max ? 'form.file_list_max' : 'form.file_list',
                'help_translation_parameters' => [
                    '%size%' => \is_int($widget->options['maxSize'] ?? null) ? $widget->options['maxSize'] : 0,
                    '%max%' => $list->max ?? 0,
                ],
            ]];
        }
        $current = \is_string($value) ? $this->files->find($value) : null;

        return [RecordFileType::class, [
            'required' => $field->isRequired,
            'current' => $current,
            'accept' => \is_string($widget->options['accept'] ?? null) ? $widget->options['accept'] : '',
            'help' => null !== $current ? 'form.file_replace' : 'form.file_max_size',
            'help_translation_parameters' => ['%size%' => \is_int($widget->options['maxSize'] ?? null) ? $widget->options['maxSize'] : 0],
        ]];
    }

    /**
     * A password is never prefilled. Left empty on an existing record, it keeps the stored one;
     * asked twice, the two inputs must match (the second one is a form-only field, not stored).
     *
     * @return array{class-string<PasswordType|RepeatedType>, array<string, mixed>}
     */
    private function password(FieldDefinitionDto $field, Widget $widget, bool $isNew): array
    {
        $required = ['required' => $isNew && $field->isRequired];
        $help = $isNew ? [] : ['help' => 'form.keep_password'];
        $attr = ['autocomplete' => 'new-password'];
        // The meter and the eye go on the password typed first, never on its confirmation.
        $controllers = [];
        $first = $attr;
        // The meter and the eye are fedale/gridview-bundle components (19_form-components.md).
        if (true === ($widget->options['strengthMeter'] ?? false)) {
            $controllers[] = 'gridview-password-strength';
            $first['data-action'] = 'gridview-password-strength#update';
            $first['data-gridview-password-strength-min-length-value'] = \is_int($widget->options['minLength'] ?? null) ? $widget->options['minLength'] : 8;
        }
        if (true === ($widget->options['revealToggle'] ?? false)) {
            $controllers[] = 'gridview-password-reveal';
        }
        if ([] !== $controllers) {
            $first['data-controller'] = implode(' ', $controllers);
        }
        if (true !== ($widget->options['confirm'] ?? false)) {
            return [PasswordType::class, $required + $help + ['attr' => $first]];
        }

        // The two inputs carry the labels and the help; the group has none of its own.
        return [RepeatedType::class, $required + [
            'label' => false,
            'type' => PasswordType::class,
            'invalid_message' => 'The two passwords do not match.',
            'options' => ['attr' => $attr],
            'first_options' => ['label' => $field->label, 'attr' => $first] + $help,
            'second_options' => ['label' => 'form.repeat', 'label_translation_parameters' => ['%label%' => mb_strtolower($field->label)]],
        ]];
    }

    /**
     * The choices of a many-to-many control: the linked ids, as the record holds them, named by
     * the labels of their records. Ids, not labels, are the keys: two tags may share a label, and
     * both must stay chosen.
     *
     * @param list<string> $ids
     *
     * @return array{choices: list<string>, choice_label: \Closure(string): string}
     */
    private function linkChoices(string $target, array $ids): array
    {
        $labels = [] !== $ids ? $this->lookup->labels($target, $ids) : [];

        return [
            'choices' => array_values(array_filter($ids, static fn (string $id): bool => isset($labels[strtolower($id)]))),
            'choice_label' => static fn (string $id): string => $labels[strtolower($id)] ?? RecordLabels::fallback($id),
        ];
    }

    private function target(Widget $widget): string
    {
        return \is_string($widget->options['target'] ?? null) ? $widget->options['target'] : throw new \LogicException('A relation widget needs a target.');
    }

    private function step(Widget $widget): string
    {
        $scale = \is_int($widget->options['scale'] ?? null) ? $widget->options['scale'] : 0;

        return $scale > 0 ? '0.'.str_repeat('0', $scale - 1).'1' : '1';
    }
}
