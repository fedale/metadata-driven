<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Workflow\Application\Definition\StepType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A definition as the visual editor works on it, and back to YAML.
 *
 * The model is tolerant: a draft being written may miss anything, and is checked when it is
 * activated. Every step has every option (empty when unused), so that the page binds to them;
 * values are rows of a field and a text; a condition is all or any of a list of tests, and a
 * condition nested deeper is kept as it was, as YAML, to edit in the YAML tab. Writing the model
 * back loses the comments of the YAML it was read from.
 */
final class EditorModel
{
    private const array STEP_OPTIONS = ['object' => '', 'id' => '', 'match' => '', 'message' => '', 'act_as' => '', 'emit_events' => false, 'items' => '', 'sort' => '', 'direction' => '', 'limit' => ''];

    /**
     * @return array<string, mixed>|null null when the YAML cannot be read
     */
    public static function fromSource(string $source): ?array
    {
        try {
            $data = Yaml::parse($source);
        } catch (ParseException) {
            return null;
        }
        if (!\is_array($data)) {
            $data = [];
        }
        $trigger = \is_array($data['trigger'] ?? null) ? $data['trigger'] : [];

        return [
            'trigger' => [
                'on' => self::text($trigger['on'] ?? 'created'),
                'object' => self::text($trigger['object'] ?? ''),
                'when' => self::text($trigger['when'] ?? 'after'),
                'fields' => \is_array($trigger['fields'] ?? null) ? array_values(array_map(self::text(...), $trigger['fields'])) : [],
                'label' => self::text($trigger['label'] ?? ''),
                'selection' => self::text($trigger['selection'] ?? ''),
                'form' => array_values(array_map(
                    static fn (mixed $input): array => \is_array($input) ? [
                        'name' => self::text($input['name'] ?? ''),
                        'label' => self::text($input['label'] ?? ''),
                        'type' => self::text($input['type'] ?? 'text'),
                        'required' => true === ($input['required'] ?? false),
                        'options' => \is_array($input['options'] ?? null) ? implode(', ', array_map(self::text(...), $input['options'])) : '',
                    ] : self::newInput(),
                    \is_array($trigger['form'] ?? null) ? $trigger['form'] : [],
                )),
            ],
            'steps' => self::stepsFrom($data['steps'] ?? []),
        ];
    }

    /**
     * @param array<mixed> $model
     */
    public static function toSource(array $model): string
    {
        $trigger = \is_array($model['trigger'] ?? null) ? $model['trigger'] : [];
        $data = ['trigger' => 'manual' === ($trigger['on'] ?? null) ? self::manualTo($trigger) : array_filter([
            'on' => self::text($trigger['on'] ?? ''),
            'object' => self::text($trigger['object'] ?? ''),
            'when' => self::text($trigger['when'] ?? ''),
            'fields' => \in_array($trigger['on'] ?? null, ['updated', 'created_or_updated'], true) && \is_array($trigger['fields'] ?? null) ? array_values(array_filter(array_map(self::text(...), $trigger['fields']))) : [],
        ], static fn (mixed $value): bool => '' !== $value && [] !== $value)];
        $data['steps'] = self::stepsTo(\is_array($model['steps'] ?? null) ? $model['steps'] : []);

        return Yaml::dump($data, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * @return array{name: string, label: string, type: string, required: bool, options: string}
     */
    public static function newInput(): array
    {
        return ['name' => '', 'label' => '', 'type' => 'text', 'required' => false, 'options' => ''];
    }

    /**
     * A new step of a type, its key free among $taken.
     *
     * @param list<string> $taken
     *
     * @return array<string, mixed>
     */
    public static function newStep(StepType $type, array $taken): array
    {
        $n = 1;
        while (\in_array($type->value.'_'.$n, $taken, true)) {
            ++$n;
        }

        return [
            'key' => $type->value.'_'.$n,
            'type' => $type->value,
            ...self::STEP_OPTIONS,
            'values' => [],
            'where' => [],
            'when' => StepType::Filter === $type ? self::newCondition() : null,
            'branches' => StepType::If === $type ? [['when' => self::newCondition(), 'steps' => []], ['when' => null, 'steps' => []]] : [],
            'steps' => [],
        ];
    }

    /**
     * A new test of a search: on a field of its object.
     *
     * @return array{field: string, op: string, value: string}
     */
    public static function newWhere(): array
    {
        return ['field' => 'id', 'op' => 'eq', 'value' => ''];
    }

    /**
     * @return array{join: string, tests: list<array{field: string, op: string, value: string}>, raw: null}
     */
    public static function newCondition(): array
    {
        return ['join' => 'all', 'tests' => [self::newTest()], 'raw' => null];
    }

    /**
     * @return array{field: string, op: string, value: string}
     */
    public static function newTest(): array
    {
        return ['field' => '$trigger.record.id', 'op' => 'eq', 'value' => ''];
    }

    /**
     * Every step key of the model, branches included.
     *
     * @param array<mixed> $model
     *
     * @return list<string>
     */
    public static function keys(array $model): array
    {
        $keys = [];
        $walk = static function (mixed $steps) use (&$walk, &$keys): void {
            foreach (\is_array($steps) ? $steps : [] as $step) {
                if (\is_array($step)) {
                    $keys[] = self::text($step['key'] ?? '');
                    foreach (\is_array($step['branches'] ?? null) ? $step['branches'] : [] as $branch) {
                        $walk(\is_array($branch) ? ($branch['steps'] ?? []) : []);
                    }
                    $walk($step['steps'] ?? []);
                }
            }
        };
        $walk($model['steps'] ?? []);

        return $keys;
    }

    /**
     * A manual trigger: its label and the inputs of its form, options as a list.
     *
     * @param array<mixed> $trigger
     *
     * @return array<string, mixed>
     */
    private static function manualTo(array $trigger): array
    {
        $form = [];
        foreach (\is_array($trigger['form'] ?? null) ? $trigger['form'] : [] as $input) {
            if (!\is_array($input)) {
                continue;
            }
            $type = self::text($input['type'] ?? 'text');
            $form[] = array_filter([
                'name' => self::text($input['name'] ?? ''),
                'label' => self::text($input['label'] ?? ''),
                'type' => $type,
                'required' => true === ($input['required'] ?? false) ? true : null,
                'options' => 'select' === $type ? array_values(array_filter(array_map(trim(...), explode(',', self::text($input['options'] ?? ''))), static fn (string $option): bool => '' !== $option)) : null,
            ], static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        return array_filter([
            'on' => 'manual',
            'object' => self::text($trigger['object'] ?? ''),
            'label' => self::text($trigger['label'] ?? ''),
            'selection' => self::text($trigger['selection'] ?? ''),
            'form' => $form,
        ], static fn (mixed $value): bool => '' !== $value && [] !== $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function stepsFrom(mixed $steps): array
    {
        $model = [];
        foreach (\is_array($steps) ? $steps : [] as $step) {
            if (!\is_array($step)) {
                continue;
            }
            $values = [];
            foreach (\is_array($step['values'] ?? null) ? $step['values'] : [] as $field => $value) {
                $values[] = ['field' => (string) $field, 'value' => self::valueText($value)];
            }
            $options = [];
            foreach (self::STEP_OPTIONS as $option => $default) {
                $options[$option] = \is_bool($default) ? true === ($step[$option] ?? false) : self::text($step[$option] ?? '');
            }
            $where = [];
            foreach (\is_array($step['where'] ?? null) ? $step['where'] : [] as $test) {
                $where[] = \is_array($test) ? self::testFrom($test) : self::newWhere();
            }
            $model[] = [
                'key' => self::text($step['key'] ?? ''),
                'type' => self::text($step['type'] ?? ''),
                ...$options,
                'values' => $values,
                'where' => $where,
                'steps' => self::stepsFrom($step['steps'] ?? []),
                'when' => \array_key_exists('when', $step) ? self::conditionFrom($step['when']) : null,
                'branches' => array_values(array_map(
                    static fn (mixed $branch): array => [
                        'when' => \is_array($branch) && \array_key_exists('when', $branch) ? self::conditionFrom($branch['when']) : null,
                        'steps' => self::stepsFrom(\is_array($branch) ? ($branch['steps'] ?? []) : []),
                    ],
                    \is_array($step['branches'] ?? null) ? $step['branches'] : [],
                )),
            ];
        }

        return $model;
    }

    /**
     * @param array<mixed> $steps
     *
     * @return list<array<string, mixed>>
     */
    private static function stepsTo(array $steps): array
    {
        $data = [];
        foreach ($steps as $step) {
            if (!\is_array($step)) {
                continue;
            }
            $type = StepType::tryFrom(self::text($step['type'] ?? ''));
            $out = ['key' => self::text($step['key'] ?? ''), 'type' => self::text($step['type'] ?? '')];
            foreach (array_keys(null === $type ? [] : $type->options()) as $option) {
                $out = match ($option) {
                    'values' => $out + ['values' => self::valuesTo($step['values'] ?? [])],
                    'when' => $out + ['when' => self::conditionTo($step['when'] ?? null)],
                    'branches' => $out + ['branches' => array_values(array_map(
                        static fn (mixed $branch): array => array_filter([
                            'when' => \is_array($branch) ? self::conditionTo($branch['when'] ?? null) : null,
                            'steps' => self::stepsTo(\is_array($branch) && \is_array($branch['steps'] ?? null) ? $branch['steps'] : []),
                        ], static fn (mixed $value): bool => null !== $value),
                        \is_array($step['branches'] ?? null) ? $step['branches'] : [],
                    ))],
                    'emit_events' => true === ($step['emit_events'] ?? false) ? $out + ['emit_events' => true] : $out,
                    'where' => [] === ($where = self::whereTo($step['where'] ?? [])) ? $out : $out + ['where' => $where],
                    'steps' => $out + ['steps' => self::stepsTo(\is_array($step['steps'] ?? null) ? $step['steps'] : [])],
                    'limit' => '' === self::text($step['limit'] ?? '') ? $out : $out + ['limit' => self::typed(self::text($step['limit'] ?? ''))],
                    default => '' === self::text($step[$option] ?? '') ? $out : $out + [$option => self::text($step[$option] ?? '')],
                };
            }
            $data[] = $out;
        }

        return $data;
    }

    /**
     * @return array{join: string, tests: list<array{field: string, op: string, value: string}>, raw: ?string}|null
     */
    private static function conditionFrom(mixed $condition): ?array
    {
        if (!\is_array($condition)) {
            return null;
        }
        foreach (['all', 'any'] as $join) {
            if (\array_key_exists($join, $condition)) {
                $tests = [];
                foreach (\is_array($condition[$join]) ? $condition[$join] : [] as $test) {
                    if (!\is_array($test) || isset($test['all']) || isset($test['any'])) {
                        // Deeper than the editor goes: kept as it is.
                        return ['join' => $join, 'tests' => [], 'raw' => Yaml::dump($condition, 0)];
                    }
                    $tests[] = self::testFrom($test);
                }

                return ['join' => $join, 'tests' => $tests, 'raw' => null];
            }
        }

        return ['join' => 'all', 'tests' => [self::testFrom($condition)], 'raw' => null];
    }

    /**
     * @param array<mixed> $test
     *
     * @return array{field: string, op: string, value: string}
     */
    private static function testFrom(array $test): array
    {
        return ['field' => self::text($test['field'] ?? ''), 'op' => self::text($test['op'] ?? 'eq'), 'value' => self::valueText($test['value'] ?? null)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function conditionTo(mixed $condition): ?array
    {
        if (!\is_array($condition)) {
            return null;
        }
        if (\is_string($condition['raw'] ?? null) && '' !== $condition['raw']) {
            $raw = Yaml::parse($condition['raw']);

            return \is_array($raw) ? array_combine(array_map(strval(...), array_keys($raw)), array_values($raw)) : null;
        }
        $tests = array_values(array_map(static function (mixed $test): array {
            $test = \is_array($test) ? $test : [];
            $op = self::text($test['op'] ?? 'eq');

            return \in_array($op, ['empty', 'not_empty'], true)
                ? ['field' => self::text($test['field'] ?? ''), 'op' => $op]
                : ['field' => self::text($test['field'] ?? ''), 'op' => $op, 'value' => self::typed(self::text($test['value'] ?? ''))];
        }, \is_array($condition['tests'] ?? null) ? $condition['tests'] : []));

        return 1 === \count($tests) ? $tests[0] : [('any' === ($condition['join'] ?? 'all') ? 'any' : 'all') => $tests];
    }

    /**
     * A search's tests, the rows with no field left out.
     *
     * @return list<array<string, mixed>>
     */
    private static function whereTo(mixed $rows): array
    {
        $where = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row) || '' === $field = self::text($row['field'] ?? '')) {
                continue;
            }
            $op = self::text($row['op'] ?? 'eq');
            $where[] = \in_array($op, ['empty', 'not_empty'], true)
                ? ['field' => $field, 'op' => $op]
                : ['field' => $field, 'op' => $op, 'value' => self::typed(self::text($row['value'] ?? ''))];
        }

        return $where;
    }

    /**
     * @return array<string, mixed>
     */
    private static function valuesTo(mixed $rows): array
    {
        $values = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && '' !== $field = self::text($row['field'] ?? '')) {
                $values[$field] = self::typed(self::text($row['value'] ?? ''));
            }
        }

        return $values;
    }

    /**
     * A value as the editor shows it: a text; a list as YAML on one line.
     */
    private static function valueText(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => Yaml::dump($value, 0),
        };
    }

    /**
     * A text of the editor as the definition holds it: true, false, numbers and lists ([a, b])
     * typed; anything else, {{ … }} paths included, a text.
     */
    private static function typed(string $text): mixed
    {
        if (\in_array($text, ['true', 'false'], true)) {
            return 'true' === $text;
        }
        if (is_numeric($text) && 1 === preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return str_contains($text, '.') ? (float) $text : (int) $text;
        }
        if (str_starts_with($text, '[') && str_ends_with($text, ']') && !str_contains($text, '{{')) {
            try {
                $list = Yaml::parse($text);

                return \is_array($list) ? $list : $text;
            } catch (ParseException) {
                return $text;
            }
        }

        return $text;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
