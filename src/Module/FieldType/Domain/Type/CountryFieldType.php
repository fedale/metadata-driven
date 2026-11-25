<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A country, as its ISO 3166-1 alpha-2 code in upper case ("IT").
 */
final class CountryFieldType extends AbstractCodeFieldType
{
    public function name(): string
    {
        return 'country';
    }

    public function label(): string
    {
        return 'Country';
    }

    public function description(): string
    {
        return 'A country, stored as its ISO code and shown by its name.';
    }

    protected static function exists(string $code): bool
    {
        return Countries::exists($code);
    }

    protected static function canonical(string $code): string
    {
        return strtoupper($code);
    }

    protected static function length(): int
    {
        return 2;
    }

    protected function constraint(): Constraint
    {
        return new Assert\Country();
    }

    protected function widgetKind(): WidgetKind
    {
        return WidgetKind::Country;
    }
}
