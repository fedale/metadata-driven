<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ManyToManySettings;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ServiceFieldDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\CascadeTargets;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordLookupInterface;
use Fedale\GridviewBundle\Form\Control\BooleanDisplay;
use Fedale\GridviewBundle\Form\Control\ChoiceDisplay;
use Fedale\GridviewBundle\Form\Control\ControlBuilder;
use Fedale\GridviewBundle\Form\Control\ControlType;
use Fedale\GridviewBundle\Form\Control\MediaFile;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the record form from the field definitions at runtime.
 *
 * The form only converts input: it carries no constraints, validation is done once
 * by the Record module with the rules stored in the metadata. The controls are those of
 * fedale/gridview-bundle (ControlBuilder), the same as in a grid's CRUD forms; see
 * docs/internal/controls-alignment-plan.md for the ones still built here.
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
        private readonly AccessCheckerInterface $access,
        private readonly CascadeTargets $cascadeTargets,
        private readonly ControlBuilder $controls,
    ) {
    }

    /**
     * @param bool $readOnly every control disabled: the user may read the record, not change it
     *
     * @return FormInterface<mixed>
     */
    public function create(ObjectDefinitionDto $object, ?RecordOutput $record = null, bool $readOnly = false): FormInterface
    {
        $builder = $this->formFactory->createNamedBuilder('record', FormType::class, $this->data($object, $record), ['disabled' => $readOnly]);
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
        foreach ($fieldNames as $name) {
            $field = $object->field($name);
            $requiredHere = \in_array($name, $required, true);
            if ($requiredHere) {
                $field = new FieldDefinitionDto($field->id, $field->name, $field->columnName, $field->type, $field->label, true, $field->isUnique, $field->settings, $field->position, $field->isSystem);
            }
            $widget = $this->fieldTypes->get($field->type)->widget($field->settings);
            $dependsOn = $field->relation()?->dependsOn;
            [$type, $options] = $this->control($field, $widget, $current[$name] ?? null, $isNew, null === $dependsOn ? null : ($current[$dependsOn] ?? null));
            // A Status: its transitions change it, the buttons of the record's page.
            if (true === ($widget->options['locked'] ?? false)) {
                $options = ['disabled' => true, 'required' => false, 'help' => 'form.changed_by_transitions'] + $options;
            }
            // A checkbox required by the form must be checked (by its object, only set).
            if ($requiredHere && WidgetKind::Boolean === $widget->kind) {
                $options['required'] = true;
            }
            if ($placeholders && !\in_array($widget->kind, [WidgetKind::Choice, WidgetKind::Relation, WidgetKind::Boolean, WidgetKind::Media], true)) {
                $options['attr'] = (\is_array($options['attr'] ?? null) ? $options['attr'] : []) + ['placeholder' => $field->label.($field->isRequired ? ' *' : '')];
            }
            $options += ['label' => $field->label];
            $options['row_attr'] = $this->rowAttr($field, $options['row_attr'] ?? []);
            $manyToMany = $field->manyToMany();
            if (null !== $manyToMany && $manyToMany->goesThrough()) {
                $this->addRows($builder, $field, $manyToMany, $options);
                continue;
            }
            $builder->add($name, $type, $options);
            if (WidgetKind::Media === $widget->kind) {
                self::fileUploads($builder->get($name));
            }
        }
    }

    /**
     * Every row names its field; the row of a field with a condition is shown and hidden by
     * the gridview-conditional controller of fedale/gridview-bundle, as the deciding field
     * changes. The server empties the field when it does not apply, whatever the browser did.
     * A cascade's row already carries gridview-dependent-select (see relationControl()).
     *
     * @return array<string, mixed>
     */
    private function rowAttr(FieldDefinitionDto $field, mixed $rowAttr): array
    {
        /** @var array<string, mixed> $rowAttr row_attr is a map of HTML attributes */
        $rowAttr = ['data-gv-field' => $field->name] + (\is_array($rowAttr) ? $rowAttr : []);
        if (null !== $field->condition) {
            $rowAttr['data-gridview-conditional-field-value'] = $field->condition->field;
            $rowAttr['data-gridview-conditional-values-value'] = json_encode($field->condition->values, \JSON_THROW_ON_ERROR);
            $controllers = \is_string($rowAttr['data-controller'] ?? null) ? $rowAttr['data-controller'] : '';
            $rowAttr['data-controller'] = trim('gridview-conditional '.$controllers);
        }

        return $rowAttr;
    }

    /**
     * A relation searched at the lookup URL of its target, through fedale/gridview-bundle:
     * the form knows the current records and the submitted ones only. A cascade is filtered by
     * the record chosen in the field it depends on (an empty filter lists nothing until one is
     * chosen), and gridview puts gridview-dependent-select on its row.
     *
     * @return array<string, mixed>
     */
    private function relationControl(FieldDefinitionDto $field, Widget $widget, mixed $parentValue): array
    {
        $target = $this->target($widget);
        $control = [
            'type' => ControlType::Relation,
            'url' => $this->urls->generate('record_lookup', ['object' => $target]),
            'labels' => $this->labelsOf($target),
        ];
        $relation = $field->relation();
        if (null !== $relation?->dependsOn && null !== $relation->dependsVia) {
            $control += [
                'dependsOn' => $relation->dependsOn,
                'dependsParam' => \sprintf('filter[%s]', $relation->dependsVia),
                'dependsValue' => \is_string($parentValue) ? $parentValue : null,
            ];
        }

        return $control;
    }

    /**
     * The labels of some records of an object, keyed by the ids as given: the lookup keys
     * them in lower case.
     *
     * @return \Closure(list<string>): array<array-key, string>
     */
    private function labelsOf(string $target): \Closure
    {
        return function (array $ids) use ($target): array {
            $ids = array_values(array_filter($ids, \is_string(...)));
            $found = $this->lookup->labels($target, $ids);
            $labels = [];
            foreach ($ids as $id) {
                if (isset($found[strtolower($id)])) {
                    $labels[$id] = $found[strtolower($id)];
                }
            }

            return $labels;
        };
    }

    /**
     * Adds the control of a field that belongs to no record (a service field of a form). A
     * required checkbox must be checked, as "I accept the terms" asks.
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
        if (WidgetKind::Boolean === $widget->kind) {
            $options['required'] = $field->isRequired;
        }
        // Named like a record field's row (data-gv-field), but apart: no condition or cascade
        // may take a service field for a field of the record.
        $builder->add($field->name, $type, $options + ['label' => $field->label, 'row_attr' => ['data-md-name' => $field->name]]);
        if (WidgetKind::Media === $widget->kind) {
            self::fileUploads($builder->get($field->name));
        }
    }

    /**
     * @param FormInterface<mixed> $form
     */
    public function addErrors(FormInterface $form, RecordValidationFailed $failure): void
    {
        foreach ($failure->errors as $path => $messages) {
            $target = self::errorTarget($form, $path);
            foreach ($messages as $message) {
                $target->addError(new FormError($message));
            }
        }
    }

    /**
     * The input an error path names ("city", "tags.0.role"), or the nearest form along it. A
     * row of a many-to-many field going through a junction object holds its fields under
     * "values": the path skips that level, as the record manager does not know it.
     *
     * @template T
     *
     * @param FormInterface<T> $form
     *
     * @return FormInterface<mixed>
     */
    public static function errorTarget(FormInterface $form, string $path): FormInterface
    {
        $target = $form;
        foreach ('' === $path ? [] : explode('.', $path) as $segment) {
            if ($target->has($segment)) {
                $target = $target->get($segment);
            } elseif ($target->has('values') && $target->get('values')->has($segment)) {
                $target = $target->get('values')->get($segment);
            } else {
                break;
            }
        }

        return $target;
    }

    /**
     * The data of a record form: the record's values, or the default values of a new record.
     * A many-to-many field going through a junction object holds its rows instead of the ids,
     * when the user may read them.
     *
     * @return array<string, mixed>
     */
    public function data(ObjectDefinitionDto $object, ?RecordOutput $record): array
    {
        if (null === $record) {
            return $this->defaults($object);
        }
        $data = $record->values;
        foreach ($object->fields as $name => $field) {
            $settings = $field->manyToMany();
            if (null === $settings || !$settings->goesThrough() || JunctionAccess::Labels === $this->junctionAccess($settings)) {
                continue;
            }
            $data[$name] = array_map(static function (RecordOutput $row) use ($settings): array {
                $values = $row->values;
                unset($values[(string) $settings->throughSource]);

                return ['id' => $row->id] + $values;
            }, $record->rows($name));
        }

        return $data;
    }

    /**
     * Whether the rows of a field going through a junction object are input: the user may
     * create, update and delete the junction's records, as saving a form asks of
     * each of its objects, and delete the records a junction record's deletion cascades to.
     * Otherwise they are only shown, and a form leaves them out.
     */
    public function editsRows(FieldDefinitionDto $field): bool
    {
        $settings = $field->manyToMany();

        return null !== $settings && $settings->goesThrough() && JunctionAccess::Rows === $this->junctionAccess($settings);
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
     * @return array{class-string<FormTypeInterface<mixed>>, array<string, mixed>}
     */
    private function control(FieldDefinitionDto $field, Widget $widget, mixed $value, bool $isNew, mixed $parentValue = null): array
    {
        return match ($widget->kind) {
            WidgetKind::Text, WidgetKind::Textarea, WidgetKind::Email, WidgetKind::Url, WidgetKind::Tel,
            WidgetKind::Integer, WidgetKind::Number, WidgetKind::Boolean, WidgetKind::Date,
            WidgetKind::DateTime, WidgetKind::Choice => $this->controls->field($this->gridviewControl($field, $widget, $isNew)),
            // The label goes on the first input of a password asked twice, so gridview needs it.
            WidgetKind::Password => $this->controls->field($this->gridviewControl($field, $widget, $isNew), $field->label),
            WidgetKind::Relation => $this->controls->field($this->gridviewControl($field, $widget, $isNew, $parentValue)),
            WidgetKind::Media => $this->controls->field($this->fileControl($field, $widget, $value)),
        };
    }

    /**
     * The fedale/gridview-bundle control of a field: its type, the field's rules as the
     * control's settings, and the few form options gridview has no setting for. The form
     * adds no constraint ("validate" false): the Record module validates the record once,
     * and its errors are put back on the form (addErrors()).
     *
     * @return array<string, mixed>
     */
    private function gridviewControl(FieldDefinitionDto $field, Widget $widget, bool $isNew, mixed $parentValue = null): array
    {
        $maxLength = \is_int($widget->options['maxLength'] ?? null) ? $widget->options['maxLength'] : null;
        $min = \is_int($widget->options['min'] ?? null) ? $widget->options['min'] : null;
        $max = \is_int($widget->options['max'] ?? null) ? $widget->options['max'] : null;
        $dates = ['widget' => 'single_text', 'input' => 'datetime_immutable'];
        $radio = 'radio' === ($widget->options['display'] ?? null);

        return match ($widget->kind) {
            WidgetKind::Text, WidgetKind::Textarea, WidgetKind::Email, WidgetKind::Tel => ['maxLength' => $maxLength],
            WidgetKind::Url => ['maxLength' => $maxLength, 'options' => ['default_protocol' => null]],
            WidgetKind::Integer => ['min' => $min, 'max' => $max],
            // The scale is the input's step: the decimals beyond it are reported, not rounded.
            WidgetKind::Number => ['min' => $min, 'max' => $max, 'scale' => \is_int($widget->options['scale'] ?? null) ? $widget->options['scale'] : 0, 'options' => ['input' => 'string', 'html5' => true]],
            // A required checkbox would have to be checked: "required" only means "not null" here.
            WidgetKind::Boolean => ['required' => false, 'display' => 'switch' === ($widget->options['display'] ?? null) ? BooleanDisplay::Switch : BooleanDisplay::Checkbox],
            WidgetKind::Date, WidgetKind::DateTime => ['options' => $dates],
            // The "radio" display of a select is drawn as buttons side by side; an empty group
            // already means "no value", so only the dropdown has a placeholder.
            WidgetKind::Choice => ['display' => $radio ? ChoiceDisplay::Segmented : ChoiceDisplay::Dropdown, 'options' => ['choices' => array_flip($widget->choices())] + ($radio ? [] : ['placeholder' => $field->isRequired ? 'form.choose_option' : '—'])],
            // A password is never prefilled: left empty on an existing record, it keeps the
            // stored one, so only a new record needs it. Asked twice, the second input is a
            // form-only field, not stored.
            WidgetKind::Password => $this->passwordControl($field, $widget, $isNew),
            // Many-to-many: the linked records, in the order they were linked; any number of them.
            WidgetKind::Relation => $widget->isMultiple()
                ? $this->relationControl($field, $widget, null) + ['options' => ['multiple' => true]]
                : $this->relationControl($field, $widget, $parentValue) + ['options' => ['placeholder' => $field->isRequired ? 'form.choose_record' : '—']],
            default => throw new \LogicException(\sprintf('Widget "%s" has no gridview control of its own yet.', $widget->kind->value)),
        } + [
            // The widget kinds are named after gridview's controls (see WidgetKind).
            'type' => ControlType::from($widget->kind->value),
            'required' => $field->isRequired,
            'validate' => false,
        ];
    }

    /**
     * The current file is looked up here, from the stored value: the form never takes a file id
     * from the request. The same for the current files of a file list, in their order. Gridview
     * draws them and binds the ids kept and the uploads (see fileUploads()).
     *
     * @return array<string, mixed>
     */
    private function fileControl(FieldDefinitionDto $field, Widget $widget, mixed $value): array
    {
        $control = [
            'type' => ControlType::Media,
            'required' => $field->isRequired,
            'validate' => false,
            'accept' => \is_string($widget->options['accept'] ?? null) ? $widget->options['accept'] : '',
        ];
        $maxSize = \is_int($widget->options['maxSize'] ?? null) ? $widget->options['maxSize'] : 0;
        $list = $field->fileList();
        if (null !== $list) {
            $ids = \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
            $found = [] !== $ids ? $this->files->findMany($ids) : [];
            $current = array_values(array_filter(array_map(static fn (string $id) => $found[strtolower($id)] ?? null, $ids)));

            return $control + [
                'multiple' => true,
                'current' => array_map(fn (StoredFile $file): MediaFile => $this->mediaFile($file, 'small'), $current),
                'options' => [
                    'help' => null !== $list->max ? 'form.file_list_max' : 'form.file_list',
                    'help_translation_parameters' => ['%size%' => $maxSize, '%max%' => $list->max ?? 0],
                ],
            ];
        }
        $current = \is_string($value) ? $this->files->find($value) : null;

        return $control + [
            'current' => null !== $current ? $this->mediaFile($current, 'medium') : null,
            'options' => [
                'help' => null !== $current ? 'form.file_replace' : 'form.file_max_size',
                'help_translation_parameters' => ['%size%' => $maxSize],
            ],
        ];
    }

    /**
     * A stored file as gridview shows it: its download link, and a thumbnail of an image.
     */
    private function mediaFile(StoredFile $file, string $thumbnail): MediaFile
    {
        return new MediaFile(
            $file->id,
            $file->originalName,
            $file->size,
            $this->urls->generate('file_download', ['id' => $file->id]),
            $file->isImage() ? $this->urls->generate('file_thumbnail', ['id' => $file->id, 'size' => $thumbnail]) : null,
        );
    }

    /**
     * Gridview binds a new file as the UploadedFile; the record pipeline takes a FileUpload. A
     * model transformer is prepended, next to the model: it sees gridview's data.
     *
     * @template T
     *
     * @param FormBuilderInterface<T> $field
     */
    private static function fileUploads(FormBuilderInterface $field): void
    {
        $toUpload = static fn (mixed $file): mixed => $file instanceof UploadedFile ? new FileUpload($file->getPathname(), $file->getClientOriginalName()) : $file;
        $field->addModelTransformer(new CallbackTransformer(
            static fn (mixed $data): mixed => $data,
            static fn (mixed $data): mixed => \is_array($data) ? array_map($toUpload, $data) : $toUpload($data),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function passwordControl(FieldDefinitionDto $field, Widget $widget, bool $isNew): array
    {
        $confirm = true === ($widget->options['confirm'] ?? false);
        $options = $isNew ? [] : ['help' => 'form.keep_password'];
        if ($confirm) {
            $options += [
                'invalid_message' => 'The two passwords do not match.',
                'second_options' => ['label' => 'form.repeat', 'label_translation_parameters' => ['%label%' => mb_strtolower($field->label)]],
            ];
        }

        return [
            'type' => ControlType::Password,
            'required' => $isNew && $field->isRequired,
            'confirm' => $confirm,
            'strengthMeter' => true === ($widget->options['strengthMeter'] ?? false),
            'revealToggle' => true === ($widget->options['revealToggle'] ?? false),
            'minLength' => \is_int($widget->options['minLength'] ?? null) ? $widget->options['minLength'] : null,
            'options' => $options,
        ];
    }

    /**
     * The control of a many-to-many field going through a junction object, per the user's
     * permissions on the junction: its rows, editable or not; or, without the read permission,
     * the labels of the linked records only, as the grid shows them.
     *
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     * @param array<string, mixed>    $options the options of the plain many-to-many control (a ChoiceType)
     */
    private function addRows(FormBuilderInterface $builder, FieldDefinitionDto $field, ManyToManySettings $settings, array $options): void
    {
        $junction = $this->metadata->getObject((string) $settings->through);
        $access = $this->junctionAccess($settings);
        if (JunctionAccess::Labels === $access) {
            $builder->add($field->name, ChoiceType::class, ['disabled' => true, 'required' => false, 'help' => 'form.through_links', 'help_translation_parameters' => ['%object%' => $junction->labelPlural]] + $options);

            return;
        }

        // The target first, then the junction's other fields; the record is the row's owner.
        $target = (string) $settings->throughTarget;
        $fields = [$target, ...array_values(array_diff(array_keys($junction->fields), [$target, (string) $settings->throughSource]))];
        // The repeatable rows of gridview's collection control, one JunctionRowType each.
        // "min" and "max" only guide its buttons: the record manager checks both.
        $label = \is_string($options['label'] ?? null) ? $options['label'] : $field->label;
        [$type, $rowsOptions] = $this->controls->field([
            'type' => ControlType::Collection,
            'required' => $field->isRequired,
            'validate' => false,
            'min' => $field->isRequired ? 1 : 0,
            'max' => $settings->max ?? 0,
            'inline' => true,
            'options' => [
                'label' => $label,
                'row_attr' => $options['row_attr'] ?? [],
                'attr' => ['class' => 'md-junction-rows'],
                'entry_type' => JunctionRowType::class,
                'entry_options' => ['junction' => $junction, 'fields' => $fields],
                // Distinct per field: a row's prototype is rendered inside the page.
                'prototype_name' => '__'.$field->name.'__',
                'prototype_data' => ['id' => null] + array_intersect_key($this->defaults($junction), array_flip($fields)),
                'disabled' => JunctionAccess::ReadOnly === $access,
                'add_label' => 'form.add',
                'add_label_translation_parameters' => ['%label%' => $label],
            ],
        ]);
        $builder->add($field->name, $type, $rowsOptions);
    }

    private function junctionAccess(ManyToManySettings $settings): JunctionAccess
    {
        $junction = (string) $settings->through;
        if (!$this->access->canRecords($junction, RecordAction::Read)) {
            return JunctionAccess::Labels;
        }
        foreach ([RecordAction::Create, RecordAction::Update, RecordAction::Delete] as $action) {
            if (!$this->access->canRecords($junction, $action)) {
                return JunctionAccess::ReadOnly;
            }
        }
        // A row removed deletes its junction record, and whatever that deletion cascades to:
        // the same rule as the delete button of a record.
        foreach (array_keys($this->cascadeTargets->of($junction)) as $target) {
            if (!$this->access->canRecords($target, RecordAction::Delete)) {
                return JunctionAccess::ReadOnly;
            }
        }

        return JunctionAccess::Rows;
    }

    private function target(Widget $widget): string
    {
        return \is_string($widget->options['target'] ?? null) ? $widget->options['target'] : throw new \LogicException('A relation widget needs a target.');
    }
}
