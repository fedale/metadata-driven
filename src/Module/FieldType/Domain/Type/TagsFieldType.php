<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Shared\Message\Message;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A short list of free text labels on a record (a ticket's keywords, a contact's interests),
 * stored as one JSON array. There is no table of labels: a new one is typed in the form. A
 * vocabulary with data of its own is a many-to-many field to an object of tags instead.
 *
 * Labels are kept as typed and compared case-insensitively: "Urgent" and "urgent" are one
 * label, the first spelling wins, and one matching a suggestion takes its spelling. A label
 * holds no comma: the form's input separates them with commas.
 */
final class TagsFieldType extends AbstractFieldType
{
    public const int MAX_LABELS = 100;
    public const int MAX_LABEL_LENGTH = 100;

    public function name(): string
    {
        return 'tags';
    }

    public function label(): string
    {
        return 'Tags';
    }

    public function description(): string
    {
        return 'Free text labels, as many as needed, typed or picked among suggestions.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('options', SettingKind::StringList, 'Suggestions', 'The labels the input suggests, in this order.', options: ['example' => ['urgent', 'later']]),
            new SettingDefinition('restrict', SettingKind::Boolean, 'Only the suggestions', 'No other label is allowed.'),
            new SettingDefinition('max', SettingKind::Integer, 'Maximum', 'How many labels a record may hold. Empty: no limit.', options: ['min' => 1, 'max' => self::MAX_LABELS]),
            new SettingDefinition('maxLength', SettingKind::Integer, 'Longest label', 'In characters.', true, ['min' => 1, 'max' => self::MAX_LABEL_LENGTH]),
        ];
    }

    public function defaultSettings(): array
    {
        return ['options' => [], 'restrict' => false, 'max' => null, 'maxLength' => 50];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $errors = [];
        $maxLength = $settings['maxLength'];
        if (!\is_int($maxLength) || $maxLength < 1 || $maxLength > self::MAX_LABEL_LENGTH) {
            $errors[] = new Message('field_type.setting_not_integer_between', 'Setting "%setting%" must be an integer between %min% and %max%.', ['setting' => 'maxLength', 'min' => 1, 'max' => self::MAX_LABEL_LENGTH]);
            $maxLength = self::MAX_LABEL_LENGTH;
        }
        if (null !== $settings['max'] && !self::isIntBetween($settings['max'], 1, self::MAX_LABELS)) {
            $errors[] = new Message('field_type.tags.max_invalid', 'Setting "max" must be an integer between 1 and %max%, or null for no limit.', ['max' => self::MAX_LABELS]);
        }
        if (!\is_bool($settings['restrict'])) {
            $errors[] = new Message('field_type.setting_not_boolean', 'Setting "%setting%" must be a boolean.', ['setting' => 'restrict']);
        }

        $options = $settings['options'];
        if (!\is_array($options) || !array_is_list($options)) {
            return [...$errors, new Message('field_type.tags.options_invalid', 'Setting "options" must be a list of labels.')];
        }
        $seen = [];
        foreach ($options as $index => $option) {
            if (!\is_string($option) || $option !== self::clean($option) || '' === $option || str_contains($option, ',') || mb_strlen($option) > $maxLength) {
                $errors[] = new Message('field_type.tags.option_invalid', 'Suggestion #%position%: a label of 1 to %max% characters, without a comma or spaces around it.', ['position' => $index, 'max' => $maxLength]);
                continue;
            }
            if (isset($seen[mb_strtolower($option)])) {
                $errors[] = new Message('field_type.tags.option_twice', 'Suggestion #%position%: "%label%" is already there.', ['position' => $index, 'label' => $option]);
            }
            $seen[mb_strtolower($option)] = true;
        }
        if (true === $settings['restrict'] && [] === $options) {
            $errors[] = new Message('field_type.tags.restrict_without_options', 'Setting "restrict" needs suggestions: they are the only labels allowed.');
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::Json);
    }

    /**
     * A list of labels, or one string of them separated by commas (the form without its
     * script, an API client sending "a, b"). Each is trimmed with its inner whitespace
     * collapsed; empty ones and case-insensitive repeats are dropped, the order is kept. No
     * label at all is null, so that "required" means "at least one".
     *
     * @return list<string>|null
     */
    public function denormalize(mixed $value, array $settings): ?array
    {
        if (null === $value) {
            return null;
        }
        $items = \is_array($value) ? $value : [$value];
        $spellings = [];
        foreach (self::options($settings) as $option) {
            $spellings[mb_strtolower($option)] ??= $option;
        }

        $labels = [];
        foreach ($items as $item) {
            if (null === $item) {
                continue;
            }
            if (!\is_string($item)) {
                throw new InvalidFieldValue('This value should be a list of labels.');
            }
            foreach (explode(',', $item) as $label) {
                $label = self::clean($label);
                if ('' !== $label) {
                    $labels[mb_strtolower($label)] ??= $spellings[mb_strtolower($label)] ?? $label;
                }
            }
        }

        return [] === $labels ? null : array_values($labels);
    }

    /**
     * Checked against what the settings are now: a record stored under laxer ones is refused
     * on its next save of the field, not rewritten.
     */
    public function constraints(array $settings): array
    {
        $constraints = [new Assert\All([new Assert\Length(max: max(1, self::intSetting($settings, 'maxLength')), maxMessage: 'A label is at most {{ limit }} characters long.')])];
        $max = self::nullableIntSetting($settings, 'max');
        if (null !== $max) {
            $constraints[] = new Assert\Count(max: max(0, $max), maxMessage: 'Give at most {{ limit }} labels.');
        }
        // denormalize() has already given each label the spelling of its suggestion.
        if (self::boolSetting($settings, 'restrict')) {
            $constraints[] = new Assert\Choice(choices: self::options($settings), multiple: true, multipleMessage: 'Choose the labels among the suggestions.');
        }

        return $constraints;
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Tags, [
            'suggestions' => self::options($settings),
            'restrict' => self::boolSetting($settings, 'restrict'),
            'max' => self::nullableIntSetting($settings, 'max'),
            'maxLength' => self::intSetting($settings, 'maxLength'),
        ]);
    }

    /**
     * A set has no order of its own, nor one text: no sort, label, search or virtual column on
     * it, and no unique.
     */
    public function isSortable(): bool
    {
        return false;
    }

    /**
     * A search term matches a record holding a label that matches it.
     */
    public function isSearchable(): bool
    {
        return true;
    }

    public function supportsUnique(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return list<string>
     */
    private static function options(array $settings): array
    {
        $options = $settings['options'] ?? null;
        if (!\is_array($options) || !array_is_list($options)) {
            throw new \LogicException('Setting "options" was not validated.');
        }

        return array_values(array_filter($options, \is_string(...)));
    }

    private static function clean(string $label): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $label));
    }
}
