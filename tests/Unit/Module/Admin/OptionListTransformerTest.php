<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Admin;

use App\Module\Admin\UI\Form\OptionListTransformer;
use PHPUnit\Framework\TestCase;

final class OptionListTransformerTest extends TestCase
{
    public function testParsesOneOptionPerLine(): void
    {
        self::assertSame([
            ['value' => 'dog', 'label' => 'Dog'],
            ['value' => 'cat', 'label' => 'cat'],
            ['value' => 'bird', 'label' => 'Bird | parrot'],
        ], (new OptionListTransformer())->reverseTransform("  dog | Dog \r\n\ncat\nbird | Bird | parrot\n"));
    }

    public function testRoundTrips(): void
    {
        $transformer = new OptionListTransformer();
        $options = [['value' => 'dog', 'label' => 'Dog'], ['value' => 'cat', 'label' => 'cat']];

        self::assertSame("dog | Dog\ncat", $transformer->transform($options));
        self::assertSame($options, $transformer->reverseTransform($transformer->transform($options)));
        self::assertSame('', $transformer->transform(null));
        self::assertSame([], $transformer->reverseTransform(null));
    }
}
