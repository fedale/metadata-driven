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
use Symfony\Component\Validator\Constraint;

/**
 * A code from an ISO list symfony/intl knows (a country, a language, a currency): stored as the
 * code, shown by its name in the user's language. Settings: the codes the form lists first, and
 * the one new records start with.
 */
abstract class AbstractCodeFieldType extends AbstractFieldType
{
    /**
     * Whether a code, in its canonical case, is in the list.
     */
    abstract protected static function exists(string $code): bool;

    /**
     * The code in its canonical case ("it" → "IT" for a country).
     */
    abstract protected static function canonical(string $code): string;

    /**
     * The longest code, the length of the column.
     */
    abstract protected static function length(): int;

    abstract protected function constraint(): Constraint;

    abstract protected function widgetKind(): WidgetKind;

    /**
     * Two codes of the list, as an example in the settings editor: the case to write them in
     * differs from one list to the other.
     *
     * @return list<string>
     */
    abstract protected static function examples(): array;

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('preferred', SettingKind::StringList, 'Listed first', 'Codes the form lists first, in this order.', options: ['example' => static::examples()]),
            new SettingDefinition('default', SettingKind::String, 'Default value', 'The code new records start with. Empty: none.'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['preferred' => [], 'default' => null];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $errors = [];
        $preferred = $settings['preferred'];
        if (!\is_array($preferred) || !array_is_list($preferred)) {
            $errors[] = new Message('field_type.code.preferred_invalid', 'Setting "preferred" must be a list of codes.');
            $preferred = [];
        }
        $seen = [];
        foreach ($preferred as $code) {
            if (!\is_string($code) || !static::exists($code)) {
                $errors[] = new Message('field_type.code.unknown', 'Setting "%setting%": "%code%" is no code of the list, written as it is there.', ['setting' => 'preferred', 'code' => \is_scalar($code) ? (string) $code : '?']);
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = new Message('field_type.code.twice', 'Setting "preferred": "%code%" is listed twice.', ['code' => $code]);
            }
            $seen[$code] = true;
        }
        $default = $settings['default'];
        if (null !== $default && (!\is_string($default) || !static::exists($default))) {
            $errors[] = new Message('field_type.code.unknown', 'Setting "%setting%": "%code%" is no code of the list, written as it is there.', ['setting' => 'default', 'code' => \is_scalar($default) ? (string) $default : '?']);
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::String, length: static::length());
    }

    /**
     * Trimmed and in the code's case; an unknown code is left for the constraint to refuse.
     */
    public function denormalize(mixed $value, array $settings): ?string
    {
        $code = self::toNullableString($value);

        return null === $code ? null : static::canonical($code);
    }

    public function constraints(array $settings): array
    {
        return [$this->constraint()];
    }

    public function widget(array $settings): Widget
    {
        $preferred = $settings['preferred'] ?? [];

        return new Widget($this->widgetKind(), ['preferred' => \is_array($preferred) ? array_values(array_filter($preferred, \is_string(...))) : []]);
    }

    public function defaultValue(array $settings): ?string
    {
        return self::nullableStringSetting($settings, 'default');
    }
}
