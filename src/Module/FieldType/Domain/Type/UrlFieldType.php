<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A web address (http or https), shown as a link.
 */
final class UrlFieldType extends AbstractFieldType
{
    private const int MAX_LENGTH = 2048;

    public function name(): string
    {
        return 'url';
    }

    public function label(): string
    {
        return 'Link';
    }

    public function description(): string
    {
        return 'A web address (http or https), shown as a link.';
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
        return [
            new Assert\Length(max: self::MAX_LENGTH),
            new Assert\Url(protocols: ['http', 'https'], requireTld: true),
        ];
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Url, ['maxLength' => self::MAX_LENGTH]);
    }
}
