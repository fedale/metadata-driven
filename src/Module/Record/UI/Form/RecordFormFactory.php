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
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
        $builder = $this->formFactory->createNamedBuilder('record', FormType::class, null !== $record ? $record->values : [], ['disabled' => $readOnly]);
        $relations = [];
        foreach ($object->fields as $name => $field) {
            $widget = $this->fieldTypes->get($field->type)->widget($field->settings);
            [$type, $options] = $this->control($field, $widget, $record?->get($name));
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
     * @return array{class-string<FormType|TextType|TextareaType|IntegerType|NumberType|CheckboxType|DateType|DateTimeType|EmailType|ChoiceType>, array<string, mixed>}
     */
    private function control(FieldDefinitionDto $field, Widget $widget, mixed $value): array
    {
        $required = ['required' => $field->isRequired];
        $maxLength = \is_int($widget->options['maxLength'] ?? null) ? ['attr' => ['maxlength' => $widget->options['maxLength']]] : [];

        return match ($widget->kind) {
            WidgetKind::Text => [TextType::class, $required + $maxLength],
            WidgetKind::Textarea => [TextareaType::class, $required + $maxLength],
            WidgetKind::Email => [EmailType::class, $required + $maxLength],
            WidgetKind::Integer => [IntegerType::class, $required],
            // No "scale": the form would silently round extra decimals instead of reporting them.
            WidgetKind::Decimal => [NumberType::class, $required + ['input' => 'string', 'html5' => true, 'attr' => ['step' => $this->step($widget)]]],
            // A required checkbox would have to be checked: "required" only means "not null" here.
            WidgetKind::Checkbox => [CheckboxType::class, ['required' => false]],
            WidgetKind::Date => [DateType::class, $required + ['widget' => 'single_text', 'input' => 'datetime_immutable']],
            WidgetKind::DateTime => [DateTimeType::class, $required + ['widget' => 'single_text', 'input' => 'datetime_immutable']],
            WidgetKind::Choice => [ChoiceType::class, $required + [
                'choices' => array_flip($widget->choices()),
                'placeholder' => $field->isRequired ? 'Choose an option' : '—',
            ]],
            WidgetKind::Relation => [ChoiceType::class, $required + [
                'choices' => \is_string($value) ? array_flip($this->lookup->labels($this->target($widget), [$value])) : [],
                'placeholder' => $field->isRequired ? 'Choose a record' : '—',
                'autocomplete' => true,
                'autocomplete_url' => $this->urls->generate('record_lookup', ['object' => $this->target($widget)]),
                'min_characters' => 1,
            ]],
        };
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
