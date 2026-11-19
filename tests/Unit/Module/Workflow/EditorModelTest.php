<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Workflow;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\EditorModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    public function testValuesKeepTheirTypeThroughTheEditor(): void
    {
        $source = <<<'YAML'
            trigger: { on: manual, object: person, label: Go }
            steps:
              - key: card
                type: create_record
                object: card
                values: { zip: '00100', phone: '0612345678', price: '1.50', flag: 'true', word: 'null', quoted: "'x'", list_text: '[a, b]', closed_at: null, note: '', big: '99999999999999999999', count: 2, ratio: 2.0, urgent: true, address: { city: Roma }, tags: ['{{ $trigger.record.id }}', open] }
              - { key: found, type: search_records, object: card, where: [{ field: zip, op: eq, value: '007' }, { field: count, op: gt, value: 7 }] }
              - { key: same, type: filter, when: { field: found.count, op: eq, value: '1' } }
              - { key: naming, type: run_workflow, workflow: Naming, input: { code: '0042' } }
            YAML;
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);
        // A text shows as it is; one that would read as something else, quoted.
        self::assertSame(['field' => 'zip', 'value' => '00100'], self::at($model, 'steps.0.values.0'));
        self::assertSame(['field' => 'price', 'value' => "'1.50'"], self::at($model, 'steps.0.values.2'));
        self::assertSame(['field' => 'flag', 'value' => "'true'"], self::at($model, 'steps.0.values.3'));
        self::assertSame(['field' => 'closed_at', 'value' => 'null'], self::at($model, 'steps.0.values.7'));
        self::assertSame(['field' => 'note', 'value' => ''], self::at($model, 'steps.0.values.8'));

        self::assertSame(self::at(self::yaml($source), 'steps'), self::at(self::yaml(EditorModel::toSource($model)), 'steps'));
    }

    public function testATextTypedInTheEditorIsANumberOnlyWhenWrittenAsOne(): void
    {
        $values = [
            ['field' => 'count', 'value' => '100'],
            ['field' => 'price', 'value' => '1.5'],
            ['field' => 'zip', 'value' => '00100'],
            ['field' => 'quoted', 'value' => "'100'"],
            ['field' => 'none', 'value' => 'null'],
            ['field' => 'path', 'value' => '{{ $trigger.record.id }}'],
            ['field' => 'paths', 'value' => '[{{ $trigger.record.id }}, open]'],
        ];
        $model = ['trigger' => ['on' => 'manual', 'object' => 'person', 'label' => 'Go'], 'steps' => [['key' => 'card', 'type' => 'create_record', 'object' => 'card', 'values' => $values]]];

        self::assertSame(
            ['count' => 100, 'price' => 1.5, 'zip' => '00100', 'quoted' => '100', 'none' => null, 'path' => '{{ $trigger.record.id }}', 'paths' => '[{{ $trigger.record.id }}, open]'],
            self::at(self::yaml(EditorModel::toSource($model)), 'steps.0.values'),
        );
    }

    public function testADateWithoutQuotesStaysTheDateItShows(): void
    {
        $source = <<<'YAML'
            trigger: { on: manual, object: invoice, label: Go }
            steps:
              - { key: due, type: update_record, object: invoice, id: '{{ $trigger.record.id }}', values: { due_on: 2026-12-31, sent_at: 2026-12-31 10:30:00, quoted: '2026-12-31' } }
              - { key: late, type: filter, when: { field: $trigger.record.due_on, op: lt, value: 2026-12-31 } }
            YAML;
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);
        // Shown as written, not as the Unix time the parser reads a YAML timestamp as.
        self::assertSame(['field' => 'due_on', 'value' => '2026-12-31'], self::at($model, 'steps.0.values.0'));
        self::assertSame(['field' => 'sent_at', 'value' => '2026-12-31T10:30:00+00:00'], self::at($model, 'steps.0.values.1'));
        self::assertSame('2026-12-31', self::at($model, 'steps.1.when.tests.0.value'));

        // Saved quoted: it reads back as the same text.
        $saved = EditorModel::toSource($model);
        self::assertStringContainsString("due_on: '2026-12-31'", $saved);
        $parser = new DefinitionParser();
        self::assertEquals($parser->parse($source), $parser->parse($saved));
    }

    public function testSelectOptionsWithACommaStayWhole(): void
    {
        $source = "trigger: { on: manual, object: person, label: Go, form: [{ name: who, label: Who, type: select, options: ['Rossi, Mario', 'Bianchi, Luca'] }] }\nsteps: [{ key: no, type: refuse, message: no }]";
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);
        self::assertSame("['Rossi, Mario', 'Bianchi, Luca']", self::at($model, 'trigger.form.0.options'));

        self::assertSame(['Rossi, Mario', 'Bianchi, Luca'], self::at(self::yaml(EditorModel::toSource($model)), 'trigger.form.0.options'));
    }

    public function testAStepOfAnUnknownTypeKeepsItsOptions(): void
    {
        $source = "trigger: { on: manual, object: person, label: Go }\nsteps: [{ key: card, type: creat_record, object: card, values: { zip: '00100' }, other: [1, 2] }]";
        $model = EditorModel::fromSource($source);
        self::assertNotNull($model);

        // A draft may hold a type mistyped: saved as it was written.
        self::assertSame(self::at(self::yaml($source), 'steps'), self::at(self::yaml(EditorModel::toSource($model)), 'steps'));

        // The type corrected on the page: the options the page showed are the step's.
        $step = self::at($model, 'steps.0');
        self::assertIsArray($step);
        $corrected = EditorModel::toSource(['trigger' => $model['trigger'], 'steps' => [['type' => 'create_record'] + $step]]);
        self::assertSame([['key' => 'card', 'type' => 'create_record', 'object' => 'card', 'values' => ['zip' => '00100']]], self::at(self::yaml($corrected), 'steps'));
    }

    public function testUnreadableYamlHasNoModel(): void
    {
        self::assertNull(EditorModel::fromSource("trigger: [\n"));
    }

    /**
     * @return array<mixed>
     */
    private static function yaml(string $source): array
    {
        $data = Yaml::parse($source);
        self::assertIsArray($data);

        return $data;
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
