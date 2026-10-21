<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;

final class BooleanFieldType extends AbstractFieldType
{
    private const array TRUE_STRINGS = ['1', 'true', 'on', 'yes'];
    private const array FALSE_STRINGS = ['0', 'false', 'off', 'no'];

    public function name(): string
    {
        return 'boolean';
    }

    public function label(): string
    {
        return 'Yes / No';
    }

    public function description(): string
    {
        return 'A checkbox.';
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::Boolean);
    }

    public function denormalize(mixed $value, array $settings): ?bool
    {
        if (null === $value || \is_bool($value)) {
            return $value;
        }
        if (0 === $value || 1 === $value) {
            return 1 === $value;
        }
        if (\is_string($value)) {
            $normalized = strtolower(trim($value));
            if ('' === $normalized) {
                return null;
            }
            if (\in_array($normalized, self::TRUE_STRINGS, true)) {
                return true;
            }
            if (\in_array($normalized, self::FALSE_STRINGS, true)) {
                return false;
            }
        }

        throw new InvalidFieldValue('This value should be true or false.');
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Checkbox);
    }
}
