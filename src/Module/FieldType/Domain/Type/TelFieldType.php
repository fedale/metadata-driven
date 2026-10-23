<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A phone number as typed: digits, an optional leading "+", spaces and the usual separators.
 */
final class TelFieldType extends AbstractFieldType
{
    private const int MAX_LENGTH = 30;

    public function name(): string
    {
        return 'tel';
    }

    public function label(): string
    {
        return 'Phone';
    }

    public function description(): string
    {
        return 'A phone number: digits, an optional leading "+", spaces, dots, dashes and brackets.';
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::String, length: self::MAX_LENGTH);
    }

    public function denormalize(mixed $value, array $settings): ?string
    {
        $value = self::toNullableString($value);

        // One space between groups, however the number was pasted.
        return null === $value ? null : (string) preg_replace('/\s+/', ' ', $value);
    }

    public function constraints(array $settings): array
    {
        return [
            new Assert\Length(max: self::MAX_LENGTH),
            new Assert\Regex('/^\+?[\d(][\d .\/()-]*\d$/', 'This value is not a valid phone number.'),
            new Assert\Regex('/^(?:\D*\d){4,15}\D*$/', 'A phone number has 4 to 15 digits.'),
        ];
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Tel, ['maxLength' => self::MAX_LENGTH]);
    }
}
