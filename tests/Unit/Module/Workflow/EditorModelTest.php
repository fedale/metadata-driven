<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Workflow;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\EditorModel;
use PHPUnit\Framework\TestCase;

final class EditorModelTest extends TestCase
{
    private const string SOURCE = <<<'YAML'
        # Reminders.
        trigger: { on: updated, object: invoice, when: after, fields: [status] }
        steps:
          - key: overdue
            type: if
            branches:
              - when: { any: [{ field: $trigger.record.status, op: eq, value: sent }, { field: $trigger.record.amount, op: gt, value: 1000 }] }
                steps:
                  - { key: reminder, type: create_record, object: reminder, values: { invoice: '{{ $trigger.record.id }}', urgent: true, tags: [a, b], times: 2 }, act_as: ROLE_CLERK, emit_events: true }
              - steps:
                  - { key: deep, type: filter, when: { all: [{ any: [{ field: $trigger.record.x, op: empty }] }] } }
          - { key: stop, type: refuse, message: 'No {{ $trigger.record.number }}' }
        YAML;

    public function testReadsAndWritesBackTheSameDefinition(): void
    {
        $model = EditorModel::fromSource(self::SOURCE);
        self::assertNotNull($model);
        self::assertSame(['field' => 'urgent', 'value' => 'true'], self::at($model, 'steps.0.branches.0.steps.0.values.1'));
        self::assertSame('any', self::at($model, 'steps.0.branches.0.when.join'));
        // Deeper than the editor goes: kept as YAML.
        self::assertNotNull(self::at($model, 'steps.0.branches.1.steps.0.when.raw'));

        $parser = new DefinitionParser();
        self::assertEquals($parser->parse(self::SOURCE), $parser->parse(EditorModel::toSource($model)));
    }

    public function testANewStepHasAFreeKeyAndEveryOption(): void
    {
        $model = EditorModel::fromSource(self::SOURCE);
        self::assertNotNull($model);
        $step = EditorModel::newStep(StepType::Filter, ['filter_1', ...EditorModel::keys($model)]);

        self::assertSame('filter_2', $step['key']);
        self::assertSame('', $step['object']);
        self::assertSame([['field' => '$trigger.record.id', 'op' => 'eq', 'value' => '']], self::at($step, 'when.tests'));
        self::assertSame(['overdue', 'reminder', 'deep', 'stop'], EditorModel::keys($model));
    }

    public function testAManualTriggerKeepsItsLabelAndItsForm(): void
    {
        $source = <<<'YAML'
            trigger: { on: manual, object: invoice, label: Remind, selection: each, form: [{ name: tone, label: Tone, type: select, required: true, options: [kind, firm] }, { name: note, label: Note, type: text }] }
            steps:
              - { key: r, type: create_record, object: reminder, values: { note: '{{ $trigger.input.note }}' } }
            YAML;
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);
        self::assertSame(['name' => 'tone', 'label' => 'Tone', 'type' => 'select', 'required' => true, 'options' => 'kind, firm'], self::at($model, 'trigger.form.0'));

        $parser = new DefinitionParser();
        self::assertEquals($parser->parse($source), $parser->parse(EditorModel::toSource($model)));
        self::assertStringNotContainsString('when:', EditorModel::toSource($model));
    }

    public function testASearchAndAnIteratorKeepTheirOptionsAndSteps(): void
    {
        $source = <<<'YAML'
            trigger: { on: manual, object: person, label: Pets }
            steps:
              - { key: pets, type: search_records, object: pet, where: [{ field: owner, op: eq, value: '{{ $trigger.record.id }}' }, { field: name, op: empty }], sort: name, direction: desc, limit: 10, act_as: ROLE_CLERK }
              - { key: each, type: iterator, items: '{{ pets.records }}', steps: [{ key: renamed, type: update_record, object: pet, id: '{{ each.item.id }}', values: { name: x } }] }
            YAML;
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);
        self::assertSame(['field' => 'name', 'op' => 'empty', 'value' => ''], self::at($model, 'steps.0.where.1'));
        self::assertSame(['pets', 'each', 'renamed'], EditorModel::keys($model));

        $parser = new DefinitionParser();
        self::assertEquals($parser->parse($source), $parser->parse(EditorModel::toSource($model)));
    }

    public function testATransitionTriggerAndStepKeepTheirOptions(): void
    {
        $source = <<<'YAML'
            trigger: { on: transitioned, object: invoice, field: state, transition: send, when: before }
            steps:
              - { key: back, type: transition, object: invoice, id: '{{ $trigger.record.id }}', field: state, name: reopen, act_as: ROLE_BOSS }
            YAML;
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);
        self::assertSame('send', self::at($model, 'trigger.transition'));

        $parser = new DefinitionParser();
        self::assertEquals($parser->parse($source), $parser->parse(EditorModel::toSource($model)));
    }

    public function testAScheduleKeepsItsCron(): void
    {
        $source = "trigger: { on: schedule, cron: '0 8 * * 1' }\nsteps: [{ key: no, type: refuse, message: no }]";
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);

        $parser = new DefinitionParser();
        self::assertEquals($parser->parse($source), $parser->parse(EditorModel::toSource($model)));
    }

    public function testACalledWorkflowAndItsCallerKeepTheirInputAndValues(): void
    {
        $parser = new DefinitionParser();
        $called = <<<'YAML'
            trigger: { on: called, input: [{ name: owner, type: text, required: true }, { name: count, label: How many, type: number }] }
            steps:
              - { key: back, type: return, values: { name: '{{ $trigger.input.owner }}' } }
            YAML;
        $model = EditorModel::fromSource($called);
        self::assertNotNull($model);
        self::assertSame('owner', self::at($model, 'trigger.form.0.name'));
        $written = EditorModel::toSource($model);
        // A label that is the name is no label.
        self::assertStringNotContainsString('label: owner', $written);
        self::assertEquals($parser->parse($called), $parser->parse($written));

        $caller = <<<'YAML'
            trigger: { on: manual, object: person, label: Go }
            steps:
              - { key: naming, type: run_workflow, workflow: Naming pets, input: { owner: '{{ $trigger.record.id }}', count: 2 } }
              - { key: done, type: run_workflow, workflow: Naming pets }
            YAML;
        $model = EditorModel::fromSource($caller);
        self::assertNotNull($model);
        self::assertSame(['field' => 'count', 'value' => '2'], self::at($model, 'steps.0.input.1'));
        self::assertEquals($parser->parse($caller), $parser->parse(EditorModel::toSource($model)));
        self::assertEquals((new DefinitionParser())->parse("trigger: { on: called }\nsteps: [{ key: r, type: return }]"), $parser->parse(EditorModel::toSource(EditorModel::fromSource("trigger: { on: called }\nsteps: [{ key: r, type: return }]") ?? [])));
    }

    public function testUnreadableYamlHasNoModel(): void
    {
        self::assertNull(EditorModel::fromSource("trigger: [\n"));
    }

    /**
     * The value at a dotted path of nested arrays; null where there is none.
     *
     * @param array<mixed> $data
     */
    private static function at(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
