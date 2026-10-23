<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Domain;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Contract\FieldTypeInterface;
use App\Module\FieldType\Contract\SecretHasherInterface;
use App\Module\FieldType\Domain\Type\BooleanFieldType;
use App\Module\FieldType\Domain\Type\DateFieldType;
use App\Module\FieldType\Domain\Type\EmailFieldType;
use App\Module\FieldType\Domain\Type\NumberFieldType;
use App\Module\FieldType\Domain\Type\PasswordFieldType;
use App\Module\FieldType\Domain\Type\SelectFieldType;
use App\Module\FieldType\Domain\Type\TelFieldType;
use App\Module\FieldType\Domain\Type\TextFieldType;
use App\Module\FieldType\Domain\Type\UrlFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

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
        yield 'password keeps spaces' => [self::password(), [], ' pass word ', ' pass word '];
        yield 'blank password is null' => [self::password(), [], '   ', null];
        yield 'url trims' => [new UrlFieldType(), [], ' https://example.com ', 'https://example.com'];
        yield 'phone collapses spaces' => [new TelFieldType(), [], " +39  333\t123 4567 ", '+39 333 123 4567'];
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
        self::assertEquals(new Widget(WidgetKind::Integer, ['min' => 1900, 'max' => 2100]), (new NumberFieldType())->widget(['decimals' => 0, 'min' => 1900, 'max' => 2100]));
        self::assertEquals(new Widget(WidgetKind::DateTime), (new DateFieldType())->widget(['includeTime' => true]));
        self::assertEquals(
            new Widget(WidgetKind::Choice, ['choices' => ['dog' => 'Dog', 'cat' => 'Cat'], 'display' => 'radio']),
            (new SelectFieldType())->widget(['options' => [['value' => 'dog', 'label' => 'Dog'], ['value' => 'cat', 'label' => 'Cat']], 'display' => 'radio']),
        );
        self::assertEquals(new Widget(WidgetKind::Checkbox, ['display' => 'switch']), (new BooleanFieldType())->widget(['default' => true, 'display' => 'switch']));
        self::assertEquals(
            new Widget(WidgetKind::Password, ['confirm' => true, 'strengthMeter' => true, 'revealToggle' => true, 'minLength' => 8]),
            self::password()->widget(['confirm' => true, 'strengthMeter' => true, 'revealToggle' => true] + self::password()->defaultSettings()),
        );
        self::assertEquals(new Widget(WidgetKind::Url, ['maxLength' => 2048]), (new UrlFieldType())->widget([]));
        self::assertEquals(new Widget(WidgetKind::Tel, ['maxLength' => 30]), (new TelFieldType())->widget([]));
    }

    public function testDefaultValues(): void
    {
        $boolean = new BooleanFieldType();
        self::assertTrue($boolean->defaultValue(['default' => true] + $boolean->defaultSettings()));
        // Unchecked means "no default": records start empty, as before the setting existed.
        self::assertNull($boolean->defaultValue($boolean->defaultSettings()));

        $select = new SelectFieldType();
        $options = ['options' => [['value' => 'dog', 'label' => 'Dog']]];
        self::assertSame('dog', $select->defaultValue(['default' => 'dog'] + $options + $select->defaultSettings()));
        self::assertNull($select->defaultValue($options + $select->defaultSettings()));
    }

    public function testPasswordsAreWriteOnlyAndStoredHashed(): void
    {
        $password = self::password();

        self::assertTrue($password->isWriteOnly());
        self::assertFalse((new TextFieldType())->isWriteOnly());
        self::assertSame('hashed:s3cret!', $password->toStorage('s3cret!', $password->defaultSettings()));
        self::assertNull($password->toStorage(null, $password->defaultSettings()));
        // Other types store the value as it is.
        self::assertSame('Rex', (new TextFieldType())->toStorage('Rex', []));
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('checkedValues')]
    public function testConstraintsCheckTheValue(FieldTypeInterface $type, array $settings, mixed $value, ?string $expectedError): void
    {
        $violations = Validation::createValidator()->validate($value, $type->constraints($settings + $type->defaultSettings()));

        if (null === $expectedError) {
            self::assertCount(0, $violations, (string) $violations);
        } else {
            self::assertStringContainsString($expectedError, (string) $violations);
        }
    }

    /**
     * @return iterable<string, array{FieldTypeInterface, array<string, mixed>, mixed, string|null}>
     */
    public static function checkedValues(): iterable
    {
        $zipcode = ['pattern' => '\d{5}', 'patternMessage' => 'Five digits.'];
        yield 'pattern matches' => [new TextFieldType(), $zipcode, '00184', null];
        yield 'pattern covers the whole value' => [new TextFieldType(), $zipcode, '001845', 'Five digits.'];
        yield 'pattern default message' => [new TextFieldType(), ['pattern' => '[A-Z]{2}'], 'mi', 'This value is not valid.'];
        yield 'number within bounds' => [new NumberFieldType(), ['min' => 1900, 'max' => 2100], 2019, null];
        yield 'number below min' => [new NumberFieldType(), ['min' => 1900, 'max' => 2100], 1850, 'between 1900 and 2100'];
        yield 'decimal above max' => [new NumberFieldType(), ['decimals' => 1, 'max' => 10], '10.5', '10 or less'];
        yield 'password long enough' => [self::password(), [], 'eight chr', null];
        yield 'password too short' => [self::password(), [], 'short', 'too short'];
        yield 'password mixed case' => [self::password(), ['requireMixedCase' => true], 'lowercase only', 'upper case letter'];
        yield 'password digit' => [self::password(), ['requireDigit' => true], 'NoDigitsHere', 'a digit'];
        yield 'password symbol' => [self::password(), ['requireSymbol' => true], 'NoSymbols123', 'a symbol'];
        yield 'password strong' => [self::password(), ['requireMixedCase' => true, 'requireDigit' => true, 'requireSymbol' => true], 'Str0ng!pass', null];
        yield 'url' => [new UrlFieldType(), [], 'https://www.credly.com/badges/123', null];
        yield 'url without scheme' => [new UrlFieldType(), [], 'www.credly.com', 'not a valid URL'];
        yield 'url other scheme' => [new UrlFieldType(), [], 'javascript:alert(1)', 'not a valid URL'];
        yield 'phone' => [new TelFieldType(), [], '+39 333 123 4567', null];
        yield 'phone with brackets' => [new TelFieldType(), [], '(02) 1234-567', null];
        yield 'phone with letters' => [new TelFieldType(), [], '333 CALL ME', 'not a valid phone number'];
        yield 'phone too short' => [new TelFieldType(), [], '123', '4 to 15 digits'];
    }

    private static function password(): PasswordFieldType
    {
        return new PasswordFieldType(new class implements SecretHasherInterface {
            public function hash(string $plain): string
            {
                return 'hashed:'.$plain;
            }

            public function verify(string $hash, string $plain): bool
            {
                return 'hashed:'.$plain === $hash;
            }
        });
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
        yield 'text with pattern' => [new TextFieldType(), ['pattern' => '\d{5}', 'patternMessage' => 'Five digits.'], new ColumnSpec(ColumnType::String, length: 255)];
        yield 'bounded number' => [new NumberFieldType(), ['min' => 0, 'max' => 100], new ColumnSpec(ColumnType::Integer)];
        yield 'switch defaulting to true' => [new BooleanFieldType(), ['default' => true, 'display' => 'switch'], new ColumnSpec(ColumnType::Boolean)];
        yield 'password' => [self::password(), ['minLength' => 12, 'requireDigit' => true, 'confirm' => true], new ColumnSpec(ColumnType::String, length: 255)];
        yield 'url' => [new UrlFieldType(), [], new ColumnSpec(ColumnType::String, length: 2048)];
        yield 'tel' => [new TelFieldType(), [], new ColumnSpec(ColumnType::String, length: 30)];
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
        yield 'invalid pattern' => [new TextFieldType(), ['pattern' => '[a-z'], 'not a valid regular expression'];
        yield 'empty pattern message' => [new TextFieldType(), ['pattern' => '\d+', 'patternMessage' => ' '], '"patternMessage"'];
        yield 'min above max' => [new NumberFieldType(), ['min' => 10, 'max' => 1], 'must not be greater'];
        yield 'boolean unknown display' => [new BooleanFieldType(), ['display' => 'toggle'], 'checkbox, switch'];
        yield 'select default not an option' => [
            new SelectFieldType(),
            ['options' => [['value' => 'dog', 'label' => 'Dog']], 'default' => 'cat'],
            'value of one of the options',
        ];
        yield 'select unknown display' => [new SelectFieldType(), ['options' => [['value' => 'dog', 'label' => 'Dog']], 'display' => 'grid'], 'dropdown, radio'];
        yield 'password minLength zero' => [self::password(), ['minLength' => 0], '"minLength"'];
        yield 'password confirm not bool' => [self::password(), ['confirm' => 'yes'], '"confirm"'];
        yield 'password strengthMeter not bool' => [self::password(), ['strengthMeter' => 1], '"strengthMeter"'];
        yield 'password revealToggle not bool' => [self::password(), ['revealToggle' => 'on'], '"revealToggle"'];
    }
}
