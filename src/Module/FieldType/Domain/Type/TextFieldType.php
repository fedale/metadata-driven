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

final class TextFieldType extends AbstractFieldType
{
    private const int MAX_VARCHAR_LENGTH = 255;
    private const int MAX_LENGTH = 10000;

    public function name(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Text';
    }

    public function description(): string
    {
        return 'Short or long text.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('maxLength', SettingKind::Integer, 'Maximum length', 'Up to 255 characters is a short text; more, a long one.', true, ['min' => 1, 'max' => self::MAX_LENGTH]),
            new SettingDefinition('multiline', SettingKind::Boolean, 'Multiline', 'A text area, keeping line breaks and indentation.'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['maxLength' => self::MAX_VARCHAR_LENGTH, 'multiline' => false];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $errors = [];
        if (!self::isIntBetween($settings['maxLength'], 1, self::MAX_LENGTH)) {
            $errors[] = \sprintf('Setting "maxLength" must be an integer between 1 and %d.', self::MAX_LENGTH);
        }
        if (!\is_bool($settings['multiline'])) {
            $errors[] = 'Setting "multiline" must be a boolean.';
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        $maxLength = self::intSetting($settings, 'maxLength');
        if (self::boolSetting($settings, 'multiline') || $maxLength > self::MAX_VARCHAR_LENGTH) {
            return new ColumnSpec(ColumnType::Text);
        }

        return new ColumnSpec(ColumnType::String, length: $maxLength);
    }

    public function denormalize(mixed $value, array $settings): ?string
    {
        // Multiline text keeps its leading and trailing whitespace (indentation, blank lines).
        return self::toNullableString($value, trim: !self::boolSetting($settings, 'multiline'));
    }

    public function constraints(array $settings): array
    {
        // max() only narrows the type: validateSettings() already guarantees maxLength >= 1.
        return [new Assert\Length(max: max(1, self::intSetting($settings, 'maxLength')))];
    }

    public function widget(array $settings): Widget
    {
        $kind = self::boolSetting($settings, 'multiline') ? WidgetKind::Textarea : WidgetKind::Text;

        return new Widget($kind, ['maxLength' => self::intSetting($settings, 'maxLength')]);
    }
}
