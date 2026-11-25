<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A language, as its ISO 639 code in lower case ("it", "fur"): those symfony/intl lists.
 */
final class LanguageFieldType extends AbstractCodeFieldType
{
    public function name(): string
    {
        return 'language';
    }

    public function label(): string
    {
        return 'Language';
    }

    public function description(): string
    {
        return 'A language, stored as its ISO code and shown by its name.';
    }

    protected static function exists(string $code): bool
    {
        return Languages::exists($code);
    }

    protected static function canonical(string $code): string
    {
        return strtolower($code);
    }

    protected static function length(): int
    {
        return 3;
    }

    protected function constraint(): Constraint
    {
        return new Assert\Language();
    }

    protected function widgetKind(): WidgetKind
    {
        return WidgetKind::Language;
    }
}
