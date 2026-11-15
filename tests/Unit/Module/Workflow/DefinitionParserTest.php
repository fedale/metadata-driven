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

    public function testReadsAManualTriggerItsLabelAndItsForm(): void
    {
        $parser = new DefinitionParser();
        $definition = $parser->parse(<<<'YAML'
            trigger:
              on: manual
              object: invoice
              label: Send a reminder
              form:
                - { name: note, label: Note, type: text, required: true }
                - { name: tone, label: Tone, type: select, options: [kind, firm] }
            steps:
              - { key: r, type: create_record, object: reminder, values: { note: '{{ $trigger.input.note }}' } }
            YAML);

        self::assertTrue($definition->trigger->isManual());
        self::assertSame('Send a reminder', $definition->trigger->label);
        self::assertSame(['kind', 'firm'], $definition->trigger->input('tone')?->options);
        self::assertTrue($definition->trigger->input('note')?->required);
        // Stored without a when, read back the same.
        $stored = $definition->toArray()['trigger'];
        self::assertIsArray($stored);
        self::assertArrayNotHasKey('when', $stored);
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
            'trigger.on' => ['One of created, updated, created_or_updated, deleted, manual.'],
            'trigger.object' => ['Required: a machine name, lowercase letters, digits and underscores.'],
            'trigger.when' => ['before or after.'],
            'trigger.fields' => ['Only for a trigger on updated or created_or_updated: the fields whose change runs it.'],
        ]];
        yield 'steps' => ["trigger: { on: created, object: invoice, when: after }\nsteps:\n  - { key: a, type: fly }\n  - { key: a, type: refuse, message: no, extra: 1 }\n  - { key: Bad, type: create_record, object: invoice }\n  - { key: c, type: set_values, values: { x: 1 } }", [
            'steps.0.type' => ['One of if, filter, create_record, update_record, delete_record, upsert_record, set_values, refuse, search_records, iterator.'],
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

    public function testReadsASearchAndAnIteratorWithItsSteps(): void
    {
        $parser = new DefinitionParser();
        $definition = $parser->parse(<<<'YAML'
            trigger: { on: manual, object: person, label: Pets }
            steps:
              - { key: pets, type: search_records, object: pet, where: [{ field: owner, op: eq, value: '{{ $trigger.record.id }}' }, { field: name, op: not_empty }], sort: name, direction: desc, limit: 10 }
              - key: each
                type: iterator
                items: '{{ pets.records }}'
                steps:
                  - { key: renamed, type: update_record, object: pet, id: '{{ each.item.id }}', values: { name: x } }
            YAML);

        self::assertSame([['field' => 'owner', 'op' => 'eq', 'value' => '{{ $trigger.record.id }}'], ['field' => 'name', 'op' => 'not_empty']], $definition->steps[0]->where());
        self::assertSame(['pets', 'each', 'renamed'], array_map(static fn ($step): string => $step->key, $definition->allSteps()));
        self::assertEquals($definition, $parser->fromArray($definition->toArray()));
    }

    public function testReportsTheProblemsOfASearchAndAnIterator(): void
    {
        try {
            (new DefinitionParser())->parse(<<<'YAML'
                trigger: { on: manual, object: person, label: Pets }
                steps:
                  - { key: pets, type: search_records, object: pet, where: [{ field: Owner, op: is, value: 1 }, { field: name, op: in, value: x }], direction: up, limit: 0 }
                  - { key: each, type: iterator, items: 'pets.records', steps: [] }
                YAML);
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame([
                'steps.0.where.0.field' => ['Required: a machine name, lowercase letters, digits and underscores.'],
                'steps.0.where.0.op' => ['One of eq, neq, lt, lte, gt, gte, contains, not_contains, starts_with, ends_with, in, between, empty, not_empty.'],
                'steps.0.where.1.value' => ['A list of values.'],
                'steps.0.direction' => ['asc or desc.'],
                'steps.0.limit' => ['A number of records, one at least.'],
                'steps.1.items' => ['One {{ path }} to a list, e.g. {{ open.records }}.'],
                'steps.1.steps' => ['Required: a list of steps, one at least.'],
            ], $e->errors);
        }
    }

    public function testReportsEveryProblemOfAManualTrigger(): void
    {
        try {
            (new DefinitionParser())->parse(<<<'YAML'
                trigger:
                  on: manual
                  object: invoice
                  when: after
                  form:
                    - { name: a, label: A, type: text }
                    - { name: a, label: Again, type: text }
                    - { name: c, label: C, type: color }
                    - { name: s, label: S, type: select }
                    - { name: t, label: T, type: text, options: [x], required: maybe }
                steps: [{ key: v, type: set_values, values: { x: 1 } }]
                YAML);
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame([
                'trigger.when' => ['Unknown: here, on, object, label, form.'],
                'trigger.label' => ['Required: the text of its button, at most 100 characters.'],
                'trigger.form.1.name' => ['"a" names another input already.'],
                'trigger.form.2.type' => ['One of text, number, date, boolean, select.'],
                'trigger.form.3.options' => ['A select takes a list of options, one at least.'],
                'trigger.form.4.options' => ['Only a select takes options.'],
                'trigger.form.4.required' => ['true or false.'],
                'steps.0.type' => ['set_values changes the values being written: only in a workflow run before the write.'],
            ], $e->errors);
        }
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
