<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordLookupInterface;
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
        $relations = [];
        foreach ($object->fields as $name => $field) {
            $widget = $this->fieldTypes->get($field->type)->widget($field->settings);
            [$type, $options] = $this->control($field, $widget, $record?->get($name), null === $record);
            $options += ['label' => $field->label];
            $builder->add($name, $type, $options);
            if (WidgetKind::Relation === $widget->kind) {
                $relations[$name] = [$options, $this->target($widget)];
            }
        }

        // A relation offers only the current record until the user searches: the submitted
        // record becomes a choice again, so that the form accepts it.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (PreSubmitEvent $event) use ($relations): void {
            $data = $event->getData();
            foreach ($relations as $name => [$options, $target]) {
                $id = \is_array($data) ? ($data[$name] ?? null) : null;
                $options['choices'] = \is_string($id) && '' !== $id ? array_flip($this->lookup->labels($target, [$id])) : [];
                $event->getForm()->add($name, ChoiceType::class, $options);
            }
        });

        return $builder->getForm();
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
    private function defaults(ObjectDefinitionDto $object): array
    {
        $values = [];
        foreach ($object->fields as $name => $field) {
            $values[$name] = $this->fieldTypes->get($field->type)->defaultValue($field->settings);
        }

        return array_filter($values, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @return array{class-string<FormType|TextType|TextareaType|IntegerType|NumberType|CheckboxType|DateType|DateTimeType|EmailType|ChoiceType|PasswordType|RepeatedType|UrlType|TelType>, array<string, mixed>}
     */
    private function control(FieldDefinitionDto $field, Widget $widget, mixed $value, bool $isNew): array
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
                ? ['attr' => ['role' => 'switch'], 'row_attr' => ['class' => 'switch-row']]
                : [])],
            WidgetKind::Date => [DateType::class, $required + ['widget' => 'single_text', 'input' => 'datetime_immutable']],
            WidgetKind::DateTime => [DateTimeType::class, $required + ['widget' => 'single_text', 'input' => 'datetime_immutable']],
            WidgetKind::Choice => [ChoiceType::class, $required + ['choices' => array_flip($widget->choices())] + ('radio' === ($widget->options['display'] ?? null)
                // No placeholder: an empty radio group already means "no value".
                ? ['expanded' => true, 'placeholder' => false, 'row_attr' => ['class' => 'segmented-row']]
                : ['placeholder' => $field->isRequired ? 'Choose an option' : '—'])],
            WidgetKind::Relation => [ChoiceType::class, $required + [
                'choices' => \is_string($value) ? array_flip($this->lookup->labels($this->target($widget), [$value])) : [],
                'placeholder' => $field->isRequired ? 'Choose a record' : '—',
                'autocomplete' => true,
                'autocomplete_url' => $this->urls->generate('record_lookup', ['object' => $this->target($widget)]),
                'min_characters' => 1,
            ]],
        };
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
        $help = $isNew ? [] : ['help' => 'Leave empty to keep the current password.'];
        if (true !== ($widget->options['confirm'] ?? false)) {
            return [PasswordType::class, $required + $help + ['attr' => ['autocomplete' => 'new-password']]];
        }

        // The two inputs carry the labels and the help; the group has none of its own.
        return [RepeatedType::class, $required + [
            'label' => false,
            'type' => PasswordType::class,
            'invalid_message' => 'The two passwords do not match.',
            'options' => ['attr' => ['autocomplete' => 'new-password']],
            'first_options' => ['label' => $field->label] + $help,
            'second_options' => ['label' => 'Repeat '.mb_strtolower($field->label)],
        ]];
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
