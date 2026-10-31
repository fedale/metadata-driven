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
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Integers are stored as int; decimals as numeric strings, to keep their exact value.
 */
final class NumberFieldType extends AbstractFieldType
{
    private const int MAX_DECIMALS = 10;
    private const int PRECISION = 18;
    private const int INTEGER_MAX = 2147483647;
    private const int INTEGER_MIN = -2147483648;

    public function name(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'Number';
    }

    public function description(): string
    {
        return 'An integer, or a decimal number with a fixed number of decimals.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('decimals', SettingKind::Integer, 'Decimals', '0 for an integer. Extra decimals are refused, never rounded.', true, ['min' => 0, 'max' => self::MAX_DECIMALS], fixed: true),
            new SettingDefinition('min', SettingKind::Integer, 'Minimum', 'The smallest value allowed. Empty: no minimum.'),
            new SettingDefinition('max', SettingKind::Integer, 'Maximum', 'The largest value allowed. Empty: no maximum.'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['decimals' => 0, 'min' => null, 'max' => null];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $errors = [];
        if (!self::isIntBetween($settings['decimals'], 0, self::MAX_DECIMALS)) {
            $errors[] = \sprintf('Setting "decimals" must be an integer between 0 and %d.', self::MAX_DECIMALS);
        }
        foreach (['min', 'max'] as $bound) {
            if (null !== $settings[$bound] && !self::isIntBetween($settings[$bound], self::INTEGER_MIN, self::INTEGER_MAX)) {
                $errors[] = \sprintf('Setting "%s" must be an integer.', $bound);
            }
        }
        if (\is_int($settings['min']) && \is_int($settings['max']) && $settings['min'] > $settings['max']) {
            $errors[] = 'Setting "min" must not be greater than "max".';
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        $decimals = self::intSetting($settings, 'decimals');
        if (0 === $decimals) {
            return new ColumnSpec(ColumnType::Integer);
        }

        return new ColumnSpec(ColumnType::Decimal, precision: self::PRECISION, scale: $decimals);
    }

    public function denormalize(mixed $value, array $settings): int|string|null
    {
        if (null === $value || (\is_string($value) && '' === trim($value))) {
            return null;
        }

        $decimals = self::intSetting($settings, 'decimals');
        $number = match (true) {
            \is_int($value) => (string) $value,
            \is_float($value) && is_finite($value) => rtrim(rtrim(\sprintf('%.'.self::MAX_DECIMALS.'F', $value), '0'), '.'),
            \is_string($value) => trim($value),
            default => throw new InvalidFieldValue('This value should be a number.'),
        };

        if (1 !== preg_match('/^-?\d+(?:\.(\d+))?$/', $number, $matches)) {
            throw new InvalidFieldValue('This value should be a number.');
        }
        $fraction = rtrim($matches[1] ?? '', '0');
        if (\strlen($fraction) > $decimals) {
            throw new InvalidFieldValue(0 === $decimals ? 'This value should be a whole number.' : \sprintf('This value should have at most %d decimal places.', $decimals));
        }

        if (0 === $decimals) {
            // Out of range strings saturate to PHP_INT_MAX/MIN and are caught by the Range constraint.
            return (int) $number;
        }

        return $number;
    }

    public function constraints(array $settings): array
    {
        $decimals = self::intSetting($settings, 'decimals');
        if (0 === $decimals) {
            $constraints = [new Assert\Range(min: self::INTEGER_MIN, max: self::INTEGER_MAX)];
        } else {
            $limit = 10 ** (self::PRECISION - $decimals);
            $constraints = [new Assert\GreaterThan(-$limit), new Assert\LessThan($limit)];
        }

        $min = self::nullableIntSetting($settings, 'min');
        $max = self::nullableIntSetting($settings, 'max');
        if (null !== $min || null !== $max) {
            $constraints[] = new Assert\Range(min: $min, max: $max);
        }

        return $constraints;
    }

    public function widget(array $settings): Widget
    {
        $decimals = self::intSetting($settings, 'decimals');
        $bounds = array_filter(['min' => self::nullableIntSetting($settings, 'min'), 'max' => self::nullableIntSetting($settings, 'max')], is_int(...));

        return 0 === $decimals
            ? new Widget(WidgetKind::Integer, $bounds)
            : new Widget(WidgetKind::Decimal, ['scale' => $decimals] + $bounds);
    }
}
