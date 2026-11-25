<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Domain;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Domain\Type\ColorFieldType;
use App\Module\FieldType\Domain\Type\TimeFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class TimeAndColorFieldTypesTest extends TestCase
{
    public function testATimeIsATimeColumn(): void
    {
        $type = new TimeFieldType();

        self::assertSame([], $type->validateSettings(['seconds' => true]));
        self::assertNotSame([], $type->validateSettings(['seconds' => 'yes']));
        self::assertEquals(new ColumnSpec(ColumnType::Time), $type->columnSpec($type->defaultSettings()));
        self::assertEquals(new Widget(WidgetKind::Time, ['seconds' => true]), $type->widget(['seconds' => true]));
        self::assertTrue($type->isSortable());
    }

    #[DataProvider('times')]
    public function testDenormalizesATime(bool $seconds, mixed $raw, ?string $expected): void
    {
        $time = (new TimeFieldType())->denormalize($raw, ['seconds' => $seconds]);

        self::assertSame($expected, $time?->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{bool, mixed, ?string}>
     */
    public static function times(): iterable
    {
        yield 'hours and minutes' => [false, '14:30', '1970-01-01 14:30:00'];
        yield 'seconds dropped' => [false, '14:30:15', '1970-01-01 14:30:00'];
        yield 'seconds kept' => [true, '14:30:15', '1970-01-01 14:30:15'];
        yield 'the time of a date-time' => [true, new \DateTimeImmutable('2026-11-25 08:05:09'), '1970-01-01 08:05:09'];
        yield 'empty' => [false, ' ', null];
    }

    public function testRefusesWhatIsNoTime(): void
    {
        $this->expectException(InvalidFieldValue::class);
        $this->expectExceptionMessage('not a valid time');

        (new TimeFieldType())->denormalize('25:00', ['seconds' => false]);
    }

    public function testAColorIsSevenCharacters(): void
    {
        $type = new ColorFieldType();

        self::assertEquals(new ColumnSpec(ColumnType::String, length: 7), $type->columnSpec([]));
        self::assertEquals(new Widget(WidgetKind::Color), $type->widget([]));
    }

    #[DataProvider('colors')]
    public function testDenormalizesAColor(mixed $raw, ?string $expected, bool $valid): void
    {
        $type = new ColorFieldType();
        $color = $type->denormalize($raw, []);

        self::assertSame($expected, $color);
        if (null !== $color) {
            self::assertSame($valid, 0 === \count(Validation::createValidator()->validate($color, $type->constraints([]))));
        }
    }

    /**
     * @return iterable<string, array{mixed, ?string, bool}>
     */
    public static function colors(): iterable
    {
        yield 'lower case' => [' #1F6FEB ', '#1f6feb', true];
        yield 'short' => ['#ABC', '#aabbcc', true];
        yield 'without the hash' => ['1f6feb', '#1f6feb', true];
        yield 'a name' => ['red', 'red', false];
        yield 'with transparency' => ['#1f6feb80', '#1f6feb80', false];
        yield 'empty' => ['', null, true];
    }
}
