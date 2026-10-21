<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Validator\Constraints as Assert;

final class EmailFieldType extends AbstractFieldType
{
    // Maximum length of an address according to RFC 5321.
    private const int MAX_LENGTH = 254;

    public function name(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function description(): string
    {
        return 'An email address, validated.';
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::String, length: self::MAX_LENGTH);
    }

    public function denormalize(mixed $value, array $settings): ?string
    {
        return self::toNullableString($value);
    }

    public function constraints(array $settings): array
    {
        return [new Assert\Length(max: self::MAX_LENGTH), new Assert\Email()];
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Email, ['maxLength' => self::MAX_LENGTH]);
    }
}
