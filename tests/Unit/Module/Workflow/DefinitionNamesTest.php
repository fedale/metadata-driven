<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Workflow;

use App\Module\Workflow\Application\Definition\DefinitionNames;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DefinitionNamesTest extends TestCase
{
    private const string SOURCE = <<<'YAML'
        trigger: { on: updated, object: invoice, when: before, fields: [status, due_on] }
        steps:
          - key: check
            type: if
            branches:
              - when: { all: [{ field: $trigger.record.status, op: eq, value: sent }, { field: $trigger.changed, op: contains, value: due_on }, { field: $trigger.object, op: eq, value: invoice }] }
                steps:
                  - { key: stamp, type: set_values, values: { note: 'Due {{ $trigger.before.due_on }}' } }
          - { key: found, type: search_records, object: reminder, where: [{ field: invoice, op: eq, value: '{{ $trigger.record.id }}' }], sort: due_on }
          - key: each
            type: iterator
            items: '{{ found.records }}'
            steps:
              - { key: late, type: filter, when: { field: each.item.due_on, op: lt, value: '{{ $trigger.record.due_on }}' } }
              - { key: move, type: update_record, object: reminder, id: '{{ each.item.id }}', values: { due_on: '{{ $trigger.record.due_on }}' } }
          - { key: log, type: upsert_record, object: invoice, match: number, values: { number: '{{ $trigger.record.number }}', status: paid } }
        YAML;

    public function testFindsEveryObjectAndFieldItNames(): void
    {
        $references = DefinitionNames::references(self::data());
        $at = static fn (string $object, ?string $field): array => array_column(array_values(array_filter($references, static fn (array $reference): bool => $reference['object'] === $object && $reference['field'] === $field)), 'at');

        self::assertSame(['trigger.object', 'steps.0.branches.0.when.all.2.value', 'steps.3.object'], $at('invoice', null));
        self::assertSame(['steps.1.object', 'steps.2.steps.1.object'], $at('reminder', null));
        self::assertSame(['trigger.fields.1', 'steps.0.branches.0.when.all.1.value', 'steps.0.branches.0.steps.0.values.note', 'steps.2.steps.0.when.value', 'steps.2.steps.1.values.due_on'], $at('invoice', 'due_on'));
        self::assertSame(['steps.1.sort', 'steps.2.steps.0.when.field', 'steps.2.steps.1.values.due_on'], $at('reminder', 'due_on'));
        self::assertSame(['steps.3.values.number', 'steps.3.values.number', 'steps.3.match'], $at('invoice', 'number'));
        // A record's id is no field.
        self::assertSame([], $at('invoice', 'id'));
        self::assertSame([], $at('reminder', 'id'));
    }

    public function testRenamesAFieldOfOneObjectOnly(): void
    {
        $renamed = DefinitionNames::renameField(self::data(), 'invoice', 'due_on', 'expires_on');

        self::assertSame(['status', 'expires_on'], self::at($renamed, 'trigger.fields'));
        self::assertSame('expires_on', self::at($renamed, 'steps.0.branches.0.when.all.1.value'));
        self::assertSame(['note' => 'Due {{ $trigger.before.expires_on }}'], self::at($renamed, 'steps.0.branches.0.steps.0.values'));
        // The reminder's due_on stays: another object's field.
        self::assertSame('due_on', self::at($renamed, 'steps.1.sort'));
        self::assertSame(['field' => 'each.item.due_on', 'op' => 'lt', 'value' => '{{ $trigger.record.expires_on }}'], self::at($renamed, 'steps.2.steps.0.when'));
        self::assertSame(['due_on' => '{{ $trigger.record.expires_on }}'], self::at($renamed, 'steps.2.steps.1.values'));
    }

    public function testRenamesAFieldThroughAnIteratorsItem(): void
    {
        $renamed = DefinitionNames::renameField(self::data(), 'reminder', 'due_on', 'remind_on');

        self::assertSame('remind_on', self::at($renamed, 'steps.1.sort'));
        self::assertSame('each.item.remind_on', self::at($renamed, 'steps.2.steps.0.when.field'));
        self::assertSame(['remind_on' => '{{ $trigger.record.due_on }}'], self::at($renamed, 'steps.2.steps.1.values'));
        self::assertSame(['status', 'due_on'], self::at($renamed, 'trigger.fields'));
    }

    public function testRenamesAnObject(): void
    {
        $renamed = DefinitionNames::renameObject(self::data(), 'invoice', 'bill');

        self::assertSame('bill', self::at($renamed, 'trigger.object'));
        self::assertSame('bill', self::at($renamed, 'steps.0.branches.0.when.all.2.value'));
        self::assertSame('bill', self::at($renamed, 'steps.3.object'));
        self::assertSame('reminder', self::at($renamed, 'steps.1.object'));
    }

    public function testTheCompiledDefinitionRenamesAsItsSource(): void
    {
        $parser = new DefinitionParser();
        $compiled = $parser->parse(self::SOURCE)->toArray();

        self::assertEquals(
            $parser->parse(Yaml::dump(DefinitionNames::renameField(self::data(), 'invoice', 'due_on', 'expires_on')))->toArray(),
            DefinitionNames::renameField($compiled, 'invoice', 'due_on', 'expires_on'),
        );
    }

    public function testAnUnfinishedDraftIsRenamedAsFarAsItGoes(): void
    {
        $draft = ['trigger' => ['on' => 'created', 'object' => 'invoice'], 'steps' => [['key' => 'x', 'type' => 'creat_record', 'object' => 'invoice', 'values' => 'oops'], 'not a step']];

        $renamed = DefinitionNames::renameObject($draft, 'invoice', 'bill');

        self::assertSame(['trigger' => ['on' => 'created', 'object' => 'bill'], 'steps' => [['key' => 'x', 'type' => 'creat_record', 'object' => 'bill', 'values' => 'oops'], 'not a step']], $renamed);
    }

    public function testATriggerWithoutARecordNamesNoObject(): void
    {
        $data = ['trigger' => ['on' => 'schedule', 'cron' => '0 8 * * 1', 'object' => 'invoice'], 'steps' => [['key' => 's', 'type' => 'search_records', 'object' => 'invoice', 'where' => [['field' => 'status', 'op' => 'eq', 'value' => '{{ $trigger.record.status }}']]]]];

        self::assertSame([
            ['object' => 'invoice', 'field' => null, 'at' => 'steps.0.object'],
            ['object' => 'invoice', 'field' => 'status', 'at' => 'steps.0.where.0.field'],
        ], DefinitionNames::references($data));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        $data = Yaml::parse(self::SOURCE);
        self::assertIsArray($data);

        return array_combine(array_map(strval(...), array_keys($data)), array_values($data));
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
