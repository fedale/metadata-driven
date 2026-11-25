<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A colour as #rrggbb, the one format the browser's colour picker edits: stored in lower case,
 * "#ABC" read as "#aabbcc". No transparency, no colour names.
 */
final class ColorFieldType extends AbstractFieldType
{
    private const int LENGTH = 7;

    public function name(): string
    {
        return 'color';
    }

    public function label(): string
    {
        return 'Color';
    }

    public function description(): string
    {
        return 'A color, picked or typed as #rrggbb.';
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::String, length: self::LENGTH);
    }

    /**
     * Trimmed, in lower case, "#" added when missing and "#abc" written out; anything else is
     * left for the constraint to refuse.
     */
    public function denormalize(mixed $value, array $settings): ?string
    {
        $color = self::toNullableString($value);
        if (null === $color) {
            return null;
        }
        $color = strtolower($color);
        if (1 === preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $short)) {
            return '#'.$short[1].$short[1].$short[2].$short[2].$short[3].$short[3];
        }

        return 1 === preg_match('/^[0-9a-f]{6}$/', $color) ? '#'.$color : $color;
    }

    public function constraints(array $settings): array
    {
        return [new Assert\Regex('/^#[0-9a-f]{6}$/', 'This value is not a valid color (#rrggbb).')];
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Color);
    }
}
