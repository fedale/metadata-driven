<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Domain;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Contract\FieldTypeInterface;
use App\Module\FieldType\Domain\Type\BooleanFieldType;
use App\Module\FieldType\Domain\Type\DateFieldType;
use App\Module\FieldType\Domain\Type\EmailFieldType;
use App\Module\FieldType\Domain\Type\NumberFieldType;
use App\Module\FieldType\Domain\Type\SelectFieldType;
use App\Module\FieldType\Domain\Type\TextFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldTypesTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('validSettings')]
    public function testValidSettingsProduceColumnSpec(FieldTypeInterface $type, array $settings, ColumnSpec $expected): void
    {
        self::assertSame([], $type->validateSettings($settings));
        self::assertEquals($expected, $type->columnSpec($settings + $type->defaultSettings()));
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreReported(FieldTypeInterface $type, array $settings, string $expectedError): void
    {
        $errors = $type->validateSettings($settings);

        self::assertNotEmpty($errors);
        self::assertStringContainsString($expectedError, implode(' ', $errors));
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('convertibleValues')]
    public function testDenormalizesRawValues(FieldTypeInterface $type, array $settings, mixed $raw, mixed $expected): void
    {
        self::assertEquals($expected, $type->denormalize($raw, $settings + $type->defaultSettings()));
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('unconvertibleValues')]
    public function testRejectsUnconvertibleValues(FieldTypeInterface $type, array $settings, mixed $raw, string $expectedMessage): void
    {
        $this->expectException(InvalidFieldValue::class);
        $this->expectExceptionMessage($expectedMessage);

        $type->denormalize($raw, $settings + $type->defaultSettings());
    }

    /**
     * @return iterable<string, array{FieldTypeInterface, array<string, mixed>, mixed, mixed}>
     */
    public static function convertibleValues(): iterable
    {
        yield 'text trims' => [new TextFieldType(), [], '  Rex ', 'Rex'];
        yield 'text blank is null' => [new TextFieldType(), [], '   ', null];
        yield 'multiline keeps whitespace' => [new TextFieldType(), ['multiline' => true], "  a\n b ", "  a\n b "];
        yield 'text from int' => [new TextFieldType(), [], 42, '42'];
        yield 'integer from string' => [new NumberFieldType(), [], '-17', -17];
        yield 'integer from float' => [new NumberFieldType(), [], 3.0, 3];
        yield 'decimal from string' => [new NumberFieldType(), ['decimals' => 2], '12.5', '12.5'];
        yield 'decimal from float' => [new NumberFieldType(), ['decimals' => 2], 0.25, '0.25'];
        yield 'decimal trailing zeros' => [new NumberFieldType(), ['decimals' => 1], '2.500', '2.500'];
        yield 'empty number is null' => [new NumberFieldType(), [], '', null];
        yield 'boolean on' => [new BooleanFieldType(), [], 'on', true];
        yield 'boolean zero' => [new BooleanFieldType(), [], 0, false];
        yield 'boolean false string' => [new BooleanFieldType(), [], 'FALSE', false];
        yield 'date string' => [new DateFieldType(), [], '2024-02-29', new \DateTimeImmutable('2024-02-29 00:00:00')];
        yield 'date drops time' => [new DateFieldType(), [], new \DateTime('2024-02-29 13:45'), new \DateTimeImmutable('2024-02-29 00:00:00')];
        yield 'datetime html input' => [new DateFieldType(), ['includeTime' => true], '2024-02-29T13:45', new \DateTimeImmutable('2024-02-29 13:45:00')];
        yield 'email trims' => [new EmailFieldType(), [], ' anna@example.com ', 'anna@example.com'];
        yield 'select value' => [new SelectFieldType(), ['options' => [['value' => 'dog', 'label' => 'Dog']]], 'dog', 'dog'];
    }

    /**
     * @return iterable<string, array{FieldTypeInterface, array<string, mixed>, mixed, string}>
     */
    public static function unconvertibleValues(): iterable
    {
        yield 'text from array' => [new TextFieldType(), [], ['a'], 'should be a string'];
        yield 'number from word' => [new NumberFieldType(), [], 'ten', 'should be a number'];
        yield 'number with comma' => [new NumberFieldType(), ['decimals' => 2], '1,5', 'should be a number'];
        yield 'integer with fraction' => [new NumberFieldType(), [], '1.5', 'whole number'];
        yield 'too many decimals' => [new NumberFieldType(), ['decimals' => 2], '1.255', 'at most 2 decimal places'];
        yield 'number infinite' => [new NumberFieldType(), [], \INF, 'should be a number'];
        yield 'boolean maybe' => [new BooleanFieldType(), [], 'maybe', 'true or false'];
        yield 'boolean two' => [new BooleanFieldType(), [], 2, 'true or false'];
        yield 'overflowing date' => [new DateFieldType(), [], '2026-02-31', 'not a valid date'];
        yield 'date with time' => [new DateFieldType(), [], '2026-02-01 10:00', 'not a valid date'];
        yield 'datetime garbage' => [new DateFieldType(), ['includeTime' => true], 'tomorrow', 'not a valid date and time'];
    }

    public function testWidgetsDescribeTheControl(): void
    {
        self::assertEquals(new Widget(WidgetKind::Text, ['maxLength' => 80]), (new TextFieldType())->widget(['maxLength' => 80, 'multiline' => false]));
        self::assertEquals(new Widget(WidgetKind::Decimal, ['scale' => 2]), (new NumberFieldType())->widget(['decimals' => 2]));
        self::assertEquals(new Widget(WidgetKind::DateTime), (new DateFieldType())->widget(['includeTime' => true]));
        self::assertEquals(
            new Widget(WidgetKind::Choice, ['choices' => ['dog' => 'Dog', 'cat' => 'Cat']]),
            (new SelectFieldType())->widget(['options' => [['value' => 'dog', 'label' => 'Dog'], ['value' => 'cat', 'label' => 'Cat']]]),
        );
    }

    /**
     * @return iterable<string, array{FieldTypeInterface, array<string, mixed>, ColumnSpec}>
     */
    public static function validSettings(): iterable
    {
        yield 'text defaults' => [new TextFieldType(), [], new ColumnSpec(ColumnType::String, length: 255)];
        yield 'short text' => [new TextFieldType(), ['maxLength' => 80], new ColumnSpec(ColumnType::String, length: 80)];
        yield 'long text' => [new TextFieldType(), ['maxLength' => 5000], new ColumnSpec(ColumnType::Text)];
        yield 'multiline text' => [new TextFieldType(), ['multiline' => true], new ColumnSpec(ColumnType::Text)];
        yield 'integer' => [new NumberFieldType(), [], new ColumnSpec(ColumnType::Integer)];
        yield 'decimal' => [new NumberFieldType(), ['decimals' => 2], new ColumnSpec(ColumnType::Decimal, precision: 18, scale: 2)];
        yield 'boolean' => [new BooleanFieldType(), [], new ColumnSpec(ColumnType::Boolean)];
        yield 'date' => [new DateFieldType(), [], new ColumnSpec(ColumnType::Date)];
        yield 'datetime' => [new DateFieldType(), ['includeTime' => true], new ColumnSpec(ColumnType::DateTime)];
        yield 'email' => [new EmailFieldType(), [], new ColumnSpec(ColumnType::String, length: 254)];
        yield 'select' => [
            new SelectFieldType(),
            ['options' => [['value' => 'dog', 'label' => 'Dog'], ['value' => 'cat', 'label' => 'Cat']]],
            new ColumnSpec(ColumnType::String, length: 100),
        ];
    }

    /**
     * @return iterable<string, array{FieldTypeInterface, array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'unknown key' => [new BooleanFieldType(), ['color' => 'red'], 'Unknown setting "color"'];
        yield 'text maxLength zero' => [new TextFieldType(), ['maxLength' => 0], '"maxLength"'];
        yield 'text maxLength string' => [new TextFieldType(), ['maxLength' => '100'], '"maxLength"'];
        yield 'text multiline not bool' => [new TextFieldType(), ['multiline' => 'yes'], '"multiline"'];
        yield 'too many decimals' => [new NumberFieldType(), ['decimals' => 11], '"decimals"'];
        yield 'date includeTime not bool' => [new DateFieldType(), ['includeTime' => 1], '"includeTime"'];
        yield 'select without options' => [new SelectFieldType(), [], 'non-empty list'];
        yield 'select options as map' => [new SelectFieldType(), ['options' => ['dog' => 'Dog']], 'non-empty list'];
        yield 'select empty value' => [new SelectFieldType(), ['options' => [['value' => '', 'label' => 'None']]], '"value"'];
        yield 'select missing label' => [new SelectFieldType(), ['options' => [['value' => 'dog']]], '"label"'];
        yield 'select duplicate value' => [
            new SelectFieldType(),
            ['options' => [['value' => 'dog', 'label' => 'Dog'], ['value' => 'dog', 'label' => 'Doggo']]],
            'duplicated',
        ];
    }
}
