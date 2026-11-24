<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Contract;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use Fedale\GridviewBundle\Form\Control\ControlType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The record forms use the controls of fedale/gridview-bundle and no control of their own: a
 * widget kind is the name of one of its controls, or the build fails here.
 */
final class WidgetKindTest extends TestCase
{
    #[DataProvider('kinds')]
    public function testEveryKindIsAGridviewControl(WidgetKind $kind): void
    {
        $controls = array_map(static fn (ControlType $control): string => $control->value, ControlType::cases());

        self::assertContains($kind->value, $controls, \sprintf('Widget kind "%s" names no control of fedale/gridview-bundle.', $kind->value));
    }

    /**
     * @return iterable<string, array{WidgetKind}>
     */
    public static function kinds(): iterable
    {
        foreach (WidgetKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }
}
