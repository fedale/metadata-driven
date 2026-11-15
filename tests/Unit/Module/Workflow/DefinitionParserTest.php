<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Workflow;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\Definition\Timing;
use App\Module\Workflow\Application\Definition\TriggerEvent;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefinitionParserTest extends TestCase
{
    private const string VALID = <<<'YAML'
        # Reminds of an invoice past due.
        trigger: { on: updated, object: invoice, when: after, fields: [status] }
        steps:
          - key: overdue
            type: if
            branches:
              - when: { all: [{ field: $trigger.record.status, op: eq, value: sent }, { field: $trigger.record.due_on, op: lt, value: '{{ $today }}' }] }
                steps:
                  - key: reminder
                    type: create_record
                    object: reminder
                    values: { invoice: '{{ $trigger.record.id }}', note: 'Invoice {{ $trigger.record.number }} is overdue' }
                    act_as: ROLE_ACCOUNTANT
              - steps:
                  - { key: paid, type: filter, when: { field: $trigger.record.paid_on, op: not_empty } }
        YAML;

    public function testReadsADefinitionAndWritesItBack(): void
    {
        $parser = new DefinitionParser();
        $definition = $parser->parse(self::VALID);

        self::assertSame(TriggerEvent::Updated, $definition->trigger->on);
        self::assertSame(Timing::After, $definition->trigger->when);
        self::assertSame(['status'], $definition->trigger->fields);
        self::assertSame(StepType::If, $definition->steps[0]->type);
        self::assertSame(['overdue', 'reminder', 'paid'], array_map(static fn ($step): string => $step->key, $definition->allSteps()));
        self::assertSame('ROLE_ACCOUNTANT', $definition->allSteps()[1]->actAs());
        self::assertNull($definition->steps[0]->branches[1]->when);

        // What is stored reads back the same.
        self::assertEquals($definition, $parser->fromArray($definition->toArray()));
    }

    /**
     * @param array<string, list<string>> $expected some of the errors, by path
     */
    #[DataProvider('invalid')]
    public function testReportsEveryProblemWhereItIs(string $yaml, array $expected): void
    {
        try {
            (new DefinitionParser())->parse($yaml);
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            foreach ($expected as $path => $messages) {
                self::assertSame($messages, $e->errors[$path] ?? null, $path.': '.json_encode($e->errors));
            }
        }
    }

    /**
     * @return iterable<string, array{string, array<string, list<string>>}>
     */
    public static function invalid(): iterable
    {
        yield 'not YAML' => ["trigger: [\n", ['' => ['Not valid YAML: Malformed inline YAML string at line 2.']]];
        yield 'no trigger, no steps' => ['{}', ['trigger' => ['Required: a map with on, object and when.'], 'steps' => ['Required: a list of steps, one at least.']]];
        yield 'a wrong trigger' => ["trigger: { on: archived, object: Invoice, when: later, fields: [x] }\nsteps: [{ key: a, type: refuse, message: no }]", [
            'trigger.on' => ['One of created, updated, created_or_updated, deleted.'],
            'trigger.object' => ['Required: a machine name, lowercase letters, digits and underscores.'],
            'trigger.when' => ['before or after.'],
            'trigger.fields' => ['Only for a trigger on updated or created_or_updated: the fields whose change runs it.'],
        ]];
        yield 'steps' => ["trigger: { on: created, object: invoice, when: after }\nsteps:\n  - { key: a, type: fly }\n  - { key: a, type: refuse, message: no, extra: 1 }\n  - { key: Bad, type: create_record, object: invoice }\n  - { key: c, type: set_values, values: { x: 1 } }", [
            'steps.0.type' => ['One of if, filter, create_record, update_record, delete_record, upsert_record, set_values, refuse.'],
            'steps.1.key' => ['"a" names another step already.'],
            'steps.1.extra' => ['Unknown: here, key, type, message.'],
            'steps.2.key' => ["Required: lowercase letters, digits and underscores, starting with a letter; it names the step's output."],
            'steps.2.values' => ['Required.'],
            'steps.3.type' => ['set_values changes the values being written: only in a workflow run before the write.'],
        ]];
        yield 'conditions and paths' => ["trigger: { on: created, object: invoice, when: before }\nsteps:\n  - key: a\n    type: if\n    branches:\n      - steps: [{ key: b, type: refuse, message: '{{ nope here }}' }]\n      - when: { field: 'x y', op: like }\n        steps: [{ key: c, type: filter, when: { any: [] } }]\n  - { key: d, type: filter, when: { field: \$trigger.record.n, op: between, value: [1] } }\n  - { key: e, type: create_record, object: invoice, values: { n: 1 }, act_as: accountant, emit_events: 'yes' }", [
            'steps.0.branches.0.when' => ['Required: only the last branch, the else, goes without.'],
            'steps.0.branches.0.steps.0.message' => ['Each {{ … }} holds a path on the data chain, e.g. {{ $trigger.record.name }}: nothing else.'],
            'steps.0.branches.1.when.field' => ['Required: a path on the data chain, e.g. $trigger.record.status.'],
            'steps.0.branches.1.when.op' => ['One of eq, neq, lt, lte, gt, gte, contains, not_contains, starts_with, ends_with, in, between, empty, not_empty.'],
            'steps.0.branches.1.steps.0.when.any' => ['A list of conditions, one at least.'],
            'steps.1.when.value' => ['A list of two values: the lowest and the highest.'],
            'steps.2.act_as' => ['A role of the workspace, e.g. ROLE_ACCOUNTANT.'],
            'steps.2.emit_events' => ['true or false.'],
        ]];
    }

    public function testCountsTheStepsOfTheBranches(): void
    {
        $steps = implode("\n", array_map(static fn (int $i): string => \sprintf('          - { key: s%d, type: refuse, message: no }', $i), range(1, 50)));
        $yaml = "trigger: { on: created, object: invoice, when: before }\nsteps:\n  - key: top\n    type: if\n    branches:\n      - steps:\n".$steps;

        try {
            (new DefinitionParser())->parse($yaml);
            self::fail('Too many steps.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['At most 50 steps, branches included: there are 51.'], $e->errors['steps']);
        }
    }
}
