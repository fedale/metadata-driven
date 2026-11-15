<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Workflow;

use App\Module\Workflow\Application\Definition\Condition;
use App\Module\Workflow\Application\Engine\ConditionEvaluator;
use App\Module\Workflow\Application\Engine\DataChain;
use PHPUnit\Framework\TestCase;

final class DataChainTest extends TestCase
{
    private DataChain $chain;

    protected function setUp(): void
    {
        $this->chain = new DataChain([
            '$trigger' => ['record' => ['id' => 'r1', 'amount' => 1200, 'status' => 'sent', 'tags' => ['a', 'b'], 'due_on' => '2026-11-10', 'paid' => false, 'note' => null]],
            '$today' => '2026-11-15',
            'reminder' => ['id' => 'x9'],
        ]);
    }

    public function testAPathAloneKeepsItsTypeAPathInATextIsText(): void
    {
        self::assertSame(1200, $this->chain->render('{{ $trigger.record.amount }}'));
        self::assertSame(['a', 'b'], $this->chain->render(' {{$trigger.record.tags}} '));
        self::assertNull($this->chain->render('{{ $trigger.record.missing.deeper }}'));
        self::assertSame('Invoice r1 of 1200, ["a","b"], paid false, note ', $this->chain->render('Invoice {{ $trigger.record.id }} of {{ $trigger.record.amount }}, {{ $trigger.record.tags }}, paid {{ $trigger.record.paid }}, note {{ $trigger.record.note }}'));
        self::assertSame(['invoice' => 'r1', 'list' => ['x9', 7]], $this->chain->render(['invoice' => '{{ $trigger.record.id }}', 'list' => ['{{ reminder.id }}', 7]]));
    }

    public function testConditions(): void
    {
        $holds = fn (string $path, string $operator, mixed $value = null): bool => (new ConditionEvaluator())->holds(Condition::test($path, $operator, $value), $this->chain);

        self::assertTrue($holds('$trigger.record.amount', 'eq', '1200'));
        self::assertTrue($holds('$trigger.record.amount', 'gt', 999.5));
        self::assertFalse($holds('$trigger.record.amount', 'lt', 1200));
        self::assertTrue($holds('$trigger.record.due_on', 'lt', '{{ $today }}'));
        self::assertTrue($holds('$trigger.record.status', 'in', ['draft', 'sent']));
        self::assertTrue($holds('$trigger.record.amount', 'between', [1000, 2000]));
        self::assertTrue($holds('$trigger.record.tags', 'contains', 'b'));
        self::assertTrue($holds('$trigger.record.status', 'contains', 'EN'));
        self::assertTrue($holds('$trigger.record.status', 'starts_with', 'se'));
        self::assertTrue($holds('$trigger.record.paid', 'eq', false));
        self::assertTrue($holds('$trigger.record.note', 'empty'));
        self::assertFalse($holds('$trigger.record.missing', 'gt', 0));
        self::assertTrue((new ConditionEvaluator())->holds(Condition::group('any', [Condition::test('$trigger.record.status', 'eq', 'draft'), Condition::test('$trigger.record.status', 'eq', 'sent')]), $this->chain));
        self::assertFalse((new ConditionEvaluator())->holds(Condition::group('all', [Condition::test('$trigger.record.status', 'eq', 'draft'), Condition::test('$trigger.record.status', 'eq', 'sent')]), $this->chain));
    }
}
