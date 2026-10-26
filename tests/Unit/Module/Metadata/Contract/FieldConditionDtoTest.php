<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Dto\FieldConditionDto;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldConditionDtoTest extends TestCase
{
    /**
     * @param list<string> $values
     */
    #[DataProvider('decidingValues')]
    public function testIsMetByTheValuesItLists(array $values, mixed $deciding, bool $met): void
    {
        self::assertSame($met, (new FieldConditionDto('kind', $values))->isMetBy($deciding));
    }

    /**
     * @return iterable<string, array{list<string>, mixed, bool}>
     */
    public static function decidingValues(): iterable
    {
        yield 'listed choice' => [['course', 'certification'], 'course', true];
        yield 'other choice' => [['course'], 'language', false];
        yield 'no choice' => [['course'], null, false];
        yield 'empty string' => [['course'], '', false];
        yield 'checked box' => [['true'], true, true];
        yield 'unchecked box' => [['true'], false, false];
        yield 'unchecked box wanted' => [['false'], false, true];
        yield 'number as string' => [['1'], 1, true];
    }

    public function testAFieldWithoutConditionAlwaysApplies(): void
    {
        $hours = new FieldDefinitionDto('', 'hours', 'hours', 'number', 'Hours', false, false, [], 0, false);
        self::assertTrue($hours->appliesTo([]));

        $hours = new FieldDefinitionDto('', 'hours', 'hours', 'number', 'Hours', false, false, [], 0, false, new FieldConditionDto('kind', ['course']));
        self::assertTrue($hours->appliesTo(['kind' => 'course']));
        self::assertFalse($hours->appliesTo(['kind' => 'language']));
        self::assertFalse($hours->appliesTo([]));
    }
}
