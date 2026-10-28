<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Form;

use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\FieldTypeInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Builds the settings part of the "new field" form from the setting definitions the field
 * type declares: a new field type shows up in the admin without touching it.
 */
final class FieldSettingsForm
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed>|null   $current the settings of an existing field: shown, and the
     *                                             fixed ones (they shape the column) disabled
     */
    public function add(FormBuilderInterface $builder, FieldTypeInterface $type, ?array $current = null): void
    {
        $settings = $builder->create('settings', FormType::class, ['label' => false, 'data' => $current ?? $type->defaultSettings()]);
        foreach ($type->settingDefinitions() as $definition) {
            [$formType, $options] = $this->control($definition);
            $fixed = null !== $current && $definition->fixed;
            $settings->add($definition->key, $formType, $options + [
                'label' => $definition->label,
                'help' => $fixed ? trim(($definition->help ?? '').' Fixed: it shapes the column.') : $definition->help,
                'required' => $definition->required && SettingKind::Boolean !== $definition->kind,
                'disabled' => $fixed,
            ]);
            if (SettingKind::OptionList === $definition->kind) {
                $settings->get($definition->key)->addModelTransformer(new OptionListTransformer());
            }
        }
        $builder->add($settings);
    }

    /**
     * The submitted settings, without the empty optional strings (they mean "use the default").
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>
     */
    public function data(FormInterface $form): array
    {
        $settings = [];
        foreach ($form->get('settings')->all() as $name => $child) {
            $value = $child->getData();
            if (null !== $value && '' !== $value) {
                $settings[(string) $name] = $value;
            }
        }

        return $settings;
    }

    /**
     * The submitted settings of an existing field, every one of them: an emptied string is null
     * (the setting is cleared), not left out.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>
     */
    public function dataForUpdate(FormInterface $form): array
    {
        $settings = [];
        foreach ($form->get('settings')->all() as $name => $child) {
            $value = $child->getData();
            $settings[(string) $name] = '' === $value ? null : $value;
        }

        return $settings;
    }

    /**
     * @return array{class-string<FormTypeInterface<mixed>>, array<string, mixed>}
     */
    private function control(SettingDefinition $definition): array
    {
        return match ($definition->kind) {
            SettingKind::Integer => [IntegerType::class, ['attr' => array_filter([
                'min' => $definition->options['min'] ?? null,
                'max' => $definition->options['max'] ?? null,
            ], static fn (mixed $bound): bool => \is_int($bound))]],
            SettingKind::Boolean => [CheckboxType::class, []],
            SettingKind::String => [TextType::class, ['empty_data' => null]],
            SettingKind::Choice => [ChoiceType::class, ['choices' => $this->choicesByLabel($definition)]],
            SettingKind::ObjectName => [ChoiceType::class, [
                'choices' => array_flip(array_map(static fn ($object): string => $object->labelPlural, $this->metadata->allObjects())),
                'placeholder' => 'Choose an object',
            ]],
            SettingKind::OptionList => [TextareaType::class, ['attr' => ['rows' => 6, 'placeholder' => "dog | Dog\ncat | Cat"]]],
        };
    }

    /**
     * @return array<string, string> label => value, as ChoiceType expects
     */
    private function choicesByLabel(SettingDefinition $definition): array
    {
        $choices = [];
        foreach (\is_array($definition->options['choices'] ?? null) ? $definition->options['choices'] : [] as $value => $label) {
            if (\is_string($label)) {
                $choices[$label] = (string) $value;
            }
        }

        return $choices;
    }
}
