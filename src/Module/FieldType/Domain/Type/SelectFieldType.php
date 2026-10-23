<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single choice among a fixed list of options; the option value is stored.
 */
final class SelectFieldType extends AbstractFieldType
{
    private const int MAX_VALUE_LENGTH = 100;
    private const array DISPLAYS = ['dropdown' => 'Drop-down list', 'radio' => 'Buttons side by side'];

    public function name(): string
    {
        return 'select';
    }

    public function label(): string
    {
        return 'Choice';
    }

    public function description(): string
    {
        return 'One value among a fixed list of options.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('options', SettingKind::OptionList, 'Options', 'The stored value and the label shown.', true),
            new SettingDefinition('default', SettingKind::String, 'Default value', 'The value of an option, chosen for new records. Empty: none.'),
            new SettingDefinition('display', SettingKind::Choice, 'Display', 'Buttons suit a few short options.', true, ['choices' => self::DISPLAYS]),
        ];
    }

    public function defaultSettings(): array
    {
        return ['options' => [], 'default' => null, 'display' => 'dropdown'];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $options = $settings['options'];
        if (!\is_array($options) || !array_is_list($options) || [] === $options) {
            return ['Setting "options" must be a non-empty list of {value, label} items.'];
        }

        $errors = [];
        $seen = [];
        foreach ($options as $index => $option) {
            $value = \is_array($option) ? ($option['value'] ?? null) : null;
            $label = \is_array($option) ? ($option['label'] ?? null) : null;
            if (!\is_string($value) || '' === $value || \strlen($value) > self::MAX_VALUE_LENGTH) {
                $errors[] = \sprintf('Option #%d: "value" must be a non-empty string of at most %d characters.', $index, self::MAX_VALUE_LENGTH);
                continue;
            }
            if (!\is_string($label) || '' === trim($label)) {
                $errors[] = \sprintf('Option #%d: "label" must be a non-empty string.', $index);
            }
            if (isset($seen[$value])) {
                $errors[] = \sprintf('Option #%d: value "%s" is duplicated.', $index, $value);
            }
            $seen[$value] = true;
        }

        $default = $settings['default'];
        if (null !== $default && (!\is_string($default) || !isset($seen[$default]))) {
            $errors[] = 'Setting "default" must be the value of one of the options.';
        }
        if (!\is_string($settings['display']) || !isset(self::DISPLAYS[$settings['display']])) {
            $errors[] = \sprintf('Setting "display" must be one of: %s.', implode(', ', array_keys(self::DISPLAYS)));
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::String, length: self::MAX_VALUE_LENGTH);
    }

    public function denormalize(mixed $value, array $settings): ?string
    {
        return self::toNullableString($value);
    }

    public function constraints(array $settings): array
    {
        return [new Assert\Choice(choices: array_keys(self::choices($settings)), message: 'The value you selected is not a valid choice.')];
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Choice, ['choices' => self::choices($settings), 'display' => $settings['display'] ?? 'dropdown']);
    }

    public function defaultValue(array $settings): ?string
    {
        return self::nullableStringSetting($settings, 'default');
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, string> value => label
     */
    private static function choices(array $settings): array
    {
        $options = $settings['options'] ?? null;
        if (!\is_array($options)) {
            throw new \LogicException('Setting "options" was not validated.');
        }

        $choices = [];
        foreach ($options as $option) {
            if (!\is_array($option) || !\is_string($option['value'] ?? null) || !\is_string($option['label'] ?? null)) {
                throw new \LogicException('Setting "options" was not validated.');
            }
            $choices[$option['value']] = $option['label'];
        }

        return $choices;
    }
}
