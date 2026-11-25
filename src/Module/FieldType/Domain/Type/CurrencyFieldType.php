<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A currency, as its ISO 4217 code in upper case ("EUR").
 */
final class CurrencyFieldType extends AbstractCodeFieldType
{
    public function name(): string
    {
        return 'currency';
    }

    public function label(): string
    {
        return 'Currency';
    }

    public function description(): string
    {
        return 'A currency, stored as its ISO code and shown by its name.';
    }

    protected static function exists(string $code): bool
    {
        return Currencies::exists($code);
    }

    protected static function canonical(string $code): string
    {
        return strtoupper($code);
    }

    protected static function length(): int
    {
        return 3;
    }

    protected function constraint(): Constraint
    {
        return new Assert\Currency();
    }

    protected function widgetKind(): WidgetKind
    {
        return WidgetKind::Currency;
    }
}
