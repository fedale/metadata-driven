<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Workflow;

use App\Module\Workflow\Application\Definition\FormInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The answers of a manual workflow's form, as the run reads them: from the page or a command.
 */
final class FormInputTest extends TestCase
{
    #[DataProvider('answers')]
    public function testTypesAnAnswerOrSaysWhyItWillNotDo(FormInput $input, mixed $answer, mixed $expected): void
    {
        try {
            self::assertSame($expected, $input->answer($answer));
        } catch (\InvalidArgumentException $e) {
            self::assertSame($expected, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{FormInput, mixed, mixed}>
     */
    public static function answers(): iterable
    {
        $number = new FormInput('n', 'N', 'number', false);
        yield 'an integer' => [$number, '12', 12];
        yield 'a decimal' => [$number, '1.5', 1.5];
        yield 'a float from the form' => [$number, 3.0, 3];
        yield 'no number' => [$number, 'twelve', 'A number.'];
        yield 'none' => [$number, '', null];
        yield 'none, required' => [new FormInput('n', 'N', 'text', true), '', 'Required.'];
        yield 'a date' => [new FormInput('d', 'D', 'date', false), '2026-02-28', '2026-02-28'];
        yield 'no such date' => [new FormInput('d', 'D', 'date', false), '2026-02-30', 'A date, as 2026-12-31.'];
        yield 'a date from the form' => [new FormInput('d', 'D', 'date', false), new \DateTimeImmutable('2026-03-01'), '2026-03-01'];
        yield 'yes' => [new FormInput('b', 'B', 'boolean', false), 'yes', true];
        yield 'unchecked' => [new FormInput('b', 'B', 'boolean', false), null, false];
        yield 'unchecked, required' => [new FormInput('b', 'B', 'boolean', true), false, 'Required.'];
        yield 'maybe' => [new FormInput('b', 'B', 'boolean', false), 'maybe', 'true or false.'];
        yield 'an option' => [new FormInput('s', 'S', 'select', false, ['kind', 'firm']), 'firm', 'firm'];
        yield 'no option' => [new FormInput('s', 'S', 'select', false, ['kind', 'firm']), 'rude', 'One of kind, firm.'];
        yield 'a list' => [new FormInput('t', 'T', 'text', false), ['a'], 'A single value.'];
    }
}
