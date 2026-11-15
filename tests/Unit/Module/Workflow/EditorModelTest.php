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
            trigger: { on: manual, object: invoice, label: Remind, form: [{ name: tone, label: Tone, type: select, required: true, options: [kind, firm] }, { name: note, label: Note, type: text }] }
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
