<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Domain;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Domain\Type\TagsFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class TagsFieldTypeTest extends TestCase
{
    public function testDescribesItsColumnAndWidget(): void
    {
        $type = new TagsFieldType();
        $settings = ['options' => ['Urgent', 'later'], 'restrict' => true, 'max' => 3] + $type->defaultSettings();

        self::assertSame([], $type->validateSettings(['options' => ['Urgent', 'later'], 'restrict' => true, 'max' => 3]));
        self::assertEquals(new ColumnSpec(ColumnType::Json), $type->columnSpec($settings));
        self::assertEquals(
            new Widget(WidgetKind::Tags, ['suggestions' => ['Urgent', 'later'], 'restrict' => true, 'max' => 3, 'maxLength' => 50]),
            $type->widget($settings),
        );
        // A set: no sort, label, search or unique on it.
        self::assertFalse($type->isSortable());
        self::assertFalse($type->supportsUnique());
        self::assertNull($type->defaultValue($settings));
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreReported(array $settings, string $expectedError): void
    {
        self::assertStringContainsString($expectedError, implode(' ', (new TagsFieldType())->validateSettings($settings)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'restrict without options' => [['restrict' => true], '"restrict" needs suggestions'];
        yield 'options not a list' => [['options' => 'urgent, later'], '"options" must be a list of labels'];
        yield 'an empty option' => [['options' => ['urgent', '']], 'Suggestion #1: a label of 1 to 50 characters'];
        yield 'an option with a comma' => [['options' => ['urgent, later']], 'Suggestion #0'];
        yield 'an option with spaces around' => [['options' => [' urgent']], 'Suggestion #0'];
        yield 'an option longer than maxLength' => [['options' => ['urgent'], 'maxLength' => 3], 'a label of 1 to 3 characters'];
        yield 'the same option twice' => [['options' => ['Urgent', 'urgent']], 'Suggestion #1: "urgent" is already there'];
        yield 'max zero' => [['max' => 0], '"max" must be an integer between 1 and 100'];
        yield 'maxLength over the ceiling' => [['maxLength' => 101], '"maxLength" must be an integer between 1 and 100'];
        yield 'restrict not bool' => [['restrict' => 'yes'], '"restrict" must be a boolean'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('rawValues')]
    public function testDenormalizesRawValues(array $settings, mixed $raw, mixed $expected): void
    {
        $type = new TagsFieldType();

        self::assertSame($expected, $type->denormalize($raw, $settings + $type->defaultSettings()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, mixed, mixed}>
     */
    public static function rawValues(): iterable
    {
        yield 'a list, trimmed, once each, in order' => [[], [' urgent ', 'client   x', 'URGENT', '', 'Later'], ['urgent', 'client x', 'Later']];
        yield 'a string of labels separated by commas' => [[], 'urgent, client x,, later', ['urgent', 'client x', 'later']];
        yield 'a label takes the spelling of its suggestion' => [['options' => ['Client X']], ['client x', 'new'], ['Client X', 'new']];
        yield 'an empty list is null' => [[], [], null];
        yield 'blanks only are null' => [[], ['', ' ', null], null];
        yield 'an empty string is null' => [[], '', null];
        yield 'null' => [[], null, null];
    }

    public function testRefusesWhatIsNoLabel(): void
    {
        $this->expectException(InvalidFieldValue::class);

        (new TagsFieldType())->denormalize([['urgent']], (new TagsFieldType())->defaultSettings());
    }

    public function testItsConstraintsCheckEachLabelTheirCountAndTheSuggestions(): void
    {
        $type = new TagsFieldType();
        $settings = ['options' => ['Urgent', 'Later'], 'restrict' => true, 'max' => 2, 'maxLength' => 10] + $type->defaultSettings();
        $validator = Validation::createValidator();
        $violations = static fn (mixed $value): array => array_map(
            static fn ($violation): string => (string) $violation->getMessage(),
            iterator_to_array($validator->validate($type->denormalize($value, $settings), $type->constraints($settings))),
        );

        self::assertSame([], $violations('urgent, later'));
        self::assertSame(['Choose the labels among the suggestions.'], $violations('urgent, someday'));
        self::assertContains('Give at most 2 labels.', $violations('urgent, later, a very long label'));
        self::assertContains('A label is at most 10 characters long.', $violations('urgent, a very long label'));
    }
}
