<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Shared\Message\Message;
use Symfony\Component\Validator\Constraints as Assert;

final class TextFieldType extends AbstractFieldType
{
    private const int MAX_VARCHAR_LENGTH = 255;
    private const int MAX_LENGTH = 10000;
    private const int MAX_PATTERN_LENGTH = 500;
    private const string DEFAULT_PATTERN_MESSAGE = 'This value is not valid.';

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
            new SettingDefinition('maxLength', SettingKind::Integer, 'Maximum length', 'Up to 255 characters is a short text; more, a long one.', true, ['min' => 1, 'max' => self::MAX_LENGTH], fixed: true),
            new SettingDefinition('multiline', SettingKind::Boolean, 'Multiline', 'A text area, keeping line breaks and indentation.', fixed: true),
            new SettingDefinition('pattern', SettingKind::String, 'Pattern', 'A regular expression (PCRE) the whole value must match, e.g. \\d{5} for a postal code. Empty: any text.'),
            new SettingDefinition('patternMessage', SettingKind::String, 'Pattern message', 'Shown when the value does not match. Default: "'.self::DEFAULT_PATTERN_MESSAGE.'"'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['maxLength' => self::MAX_VARCHAR_LENGTH, 'multiline' => false, 'pattern' => null, 'patternMessage' => null];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $errors = [];
        if (!self::isIntBetween($settings['maxLength'], 1, self::MAX_LENGTH)) {
            $errors[] = new Message('field_type.setting_not_integer_between', 'Setting "%setting%" must be an integer between %min% and %max%.', ['setting' => 'maxLength', 'min' => 1, 'max' => self::MAX_LENGTH]);
        }
        if (!\is_bool($settings['multiline'])) {
            $errors[] = new Message('field_type.setting_not_boolean', 'Setting "%setting%" must be a boolean.', ['setting' => 'multiline']);
        }
        $pattern = $settings['pattern'];
        if (null !== $pattern && (!\is_string($pattern) || '' === $pattern || \strlen($pattern) > self::MAX_PATTERN_LENGTH)) {
            $errors[] = new Message('field_type.text.pattern_invalid', 'Setting "pattern" must be a regular expression of at most %max% characters.', ['max' => self::MAX_PATTERN_LENGTH]);
        } elseif (\is_string($pattern) && false === @preg_match(self::regex($pattern), '')) {
            $errors[] = new Message('field_type.text.pattern_not_regex', 'Setting "pattern" is not a valid regular expression: %error%.', ['error' => preg_last_error_msg()]);
        }
        $message = $settings['patternMessage'];
        if (null !== $message && (!\is_string($message) || '' === trim($message))) {
            $errors[] = new Message('field_type.text.pattern_message_invalid', 'Setting "patternMessage" must be a non-empty string.');
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
        $constraints = [new Assert\Length(max: max(1, self::intSetting($settings, 'maxLength')))];
        $pattern = self::nullableStringSetting($settings, 'pattern');
        if (null !== $pattern) {
            $constraints[] = new Assert\Regex(
                pattern: self::regex($pattern),
                message: self::nullableStringSetting($settings, 'patternMessage') ?? self::DEFAULT_PATTERN_MESSAGE,
            );
        }

        return $constraints;
    }

    public function widget(array $settings): Widget
    {
        $kind = self::boolSetting($settings, 'multiline') ? WidgetKind::Textarea : WidgetKind::Text;

        return new Widget($kind, ['maxLength' => self::intSetting($settings, 'maxLength')]);
    }

    /**
     * The pattern anchored on the whole value, as an HTML "pattern" attribute is. The
     * delimiter is a control character, so that the pattern needs no escaping.
     */
    private static function regex(string $pattern): string
    {
        return "\x01^(?:".$pattern.")$\x01u";
    }
}
