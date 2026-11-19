<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\Definition\TriggerEvent;
use App\Module\Workflow\Application\Definition\WorkflowYaml;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A definition as the visual editor works on it, and back to YAML.
 *
 * The model is tolerant: a draft being written may miss anything, and is checked when it is
 * activated. Every step has every option (empty when unused), so that the page binds to them;
 * values are rows of a field and a text; a condition is all or any of a list of tests, and a
 * condition nested deeper is kept as it was, as YAML, to edit in the YAML tab. Writing the model
 * back loses the comments of the YAML it was read from, and nothing else: a value keeps its type
 * (see valueText() and typed()), and a step of a type unknown is kept as it was written.
 */
final class EditorModel
{
    private const array STEP_OPTIONS = ['object' => '', 'id' => '', 'match' => '', 'message' => '', 'act_as' => '', 'emit_events' => false, 'items' => '', 'sort' => '', 'direction' => '', 'limit' => '', 'package' => '', 'repo' => '', 'email' => '', 'role' => '', 'for' => '', 'until' => '', 'url' => '', 'method' => '', 'timeout' => '', 'retries' => '', 'headers' => '', 'body' => '', 'fail_on_error' => true, 'field' => '', 'name' => '', 'workflow' => '', 'subject' => '', 'title' => '', 'text' => '', 'link_object' => '', 'link_id' => ''];

    /** Who a send_email or a notify may name. */
    public const array RECIPIENT_KINDS = ['role', 'member', 'field'];

    /**
     * @return array<string, mixed>|null null when the YAML cannot be read
     */
    public static function fromSource(string $source): ?array
    {
        try {
            $data = WorkflowYaml::parse($source);
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
                'field' => self::text($trigger['field'] ?? ''),
                'transition' => self::text($trigger['transition'] ?? ''),
                'cron' => self::text($trigger['cron'] ?? ''),
                'form' => array_values(array_map(
                    static fn (mixed $input): array => \is_array($input) ? [
                        'name' => self::text($input['name'] ?? ''),
                        'label' => self::text($input['label'] ?? ''),
                        'type' => self::text($input['type'] ?? 'text'),
                        'required' => true === ($input['required'] ?? false),
                        'options' => \is_array($input['options'] ?? null) ? self::optionsText(array_values(array_map(self::text(...), $input['options']))) : '',
                    ] : self::newInput(),
                    // A called trigger's inputs, edited as a manual one's form.
                    \is_array($trigger['form'] ?? null) ? $trigger['form'] : (\is_array($trigger['input'] ?? null) ? $trigger['input'] : []),
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
        $on = TriggerEvent::tryFrom(self::text($trigger['on'] ?? ''));
        $data = ['trigger' => TriggerEvent::Called === $on ? self::calledTo($trigger) : (TriggerEvent::Schedule === $on ? ['on' => $on->value, 'cron' => self::text($trigger['cron'] ?? '')] : (TriggerEvent::Webhook === $on ? ['on' => $on->value] : (true === $on?->isPlatform() ? ['on' => $on->value] : ('manual' === ($trigger['on'] ?? null) ? self::manualTo($trigger) : array_filter([
            'on' => self::text($trigger['on'] ?? ''),
            'object' => self::text($trigger['object'] ?? ''),
            'when' => self::text($trigger['when'] ?? ''),
            'fields' => \in_array($trigger['on'] ?? null, ['updated', 'created_or_updated'], true) && \is_array($trigger['fields'] ?? null) ? array_values(array_filter(array_map(self::text(...), $trigger['fields']))) : [],
            // A transition's: its Status field, and the transition or any.
            'field' => 'transitioned' === ($trigger['on'] ?? null) ? self::text($trigger['field'] ?? '') : '',
            'transition' => 'transitioned' === ($trigger['on'] ?? null) ? self::text($trigger['transition'] ?? '') : '',
        ], static fn (mixed $value): bool => '' !== $value && [] !== $value)))))];
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
            'input' => [],
            'where' => [],
            'to' => \in_array($type, [StepType::SendEmail, StepType::Notify], true) ? [self::newRecipient()] : [],
            'when' => StepType::Filter === $type ? self::newCondition() : null,
            'branches' => StepType::If === $type ? [['when' => self::newCondition(), 'steps' => []], ['when' => null, 'steps' => []]] : [],
            'steps' => [],
        ];
    }

    /**
     * A new recipient of a send_email or a notify: a member, by a path or an email.
     *
     * @return array{kind: string, value: string}
     */
    public static function newRecipient(): array
    {
        return ['kind' => 'member', 'value' => ''];
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
     * A called trigger: its inputs, as a manual trigger's form (the label left out when it is
     * the name, or none).
     *
     * @param array<mixed> $trigger
     *
     * @return array<string, mixed>
     */
    private static function calledTo(array $trigger): array
    {
        $form = self::manualTo($trigger)['form'] ?? [];
        $inputs = array_map(static fn (mixed $input): mixed => \is_array($input) && ($input['label'] ?? null) === ($input['name'] ?? null) ? array_diff_key($input, ['label' => true]) : $input, \is_array($form) ? $form : []);

        return array_filter(['on' => TriggerEvent::Called->value, 'input' => array_values($inputs)], static fn (mixed $value): bool => [] !== $value);
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
                'options' => 'select' === $type ? self::optionsFrom(self::text($input['options'] ?? '')) : null,
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
            $input = [];
            foreach (\is_array($step['input'] ?? null) ? $step['input'] : [] as $name => $value) {
                $input[] = ['field' => (string) $name, 'value' => self::valueText($value)];
            }
            $options = [];
            foreach (self::STEP_OPTIONS as $option => $default) {
                $options[$option] = match (true) {
                    \is_bool($default) => \is_bool($step[$option] ?? null) ? $step[$option] : $default,
                    // A request's headers and body: YAML on one line, to edit as text.
                    \in_array($option, ['headers', 'body'], true) => \is_array($step[$option] ?? null) ? Yaml::dump($step[$option], 0) : self::text($step[$option] ?? ''),
                    default => self::text($step[$option] ?? ''),
                };
            }
            $where = [];
            foreach (\is_array($step['where'] ?? null) ? $step['where'] : [] as $test) {
                $where[] = \is_array($test) ? self::testFrom($test) : self::newWhere();
            }
            $to = [];
            foreach (\is_array($step['to'] ?? null) ? $step['to'] : [] as $entry) {
                $kind = \is_array($entry) ? (string) array_key_first($entry) : '';
                $to[] = \in_array($kind, self::RECIPIENT_KINDS, true) ? ['kind' => $kind, 'value' => self::text($entry[$kind] ?? '')] : self::newRecipient();
            }
            // A notification's record: its object and id, two fields of the page.
            $link = \is_array($step['link'] ?? null) ? $step['link'] : [];
            $options['link_object'] = self::text($link['object'] ?? '');
            $options['link_id'] = self::text($link['id'] ?? '');
            $model[] = [
                'key' => self::text($step['key'] ?? ''),
                'type' => self::text($step['type'] ?? ''),
                // A type the editor does not know (mistyped, in a draft): the step as it was written,
                // for the options the page cannot show.
                'raw' => null === StepType::tryFrom(self::text($step['type'] ?? '')) ? Yaml::dump($step, 0) : null,
                ...$options,
                'values' => $values,
                'input' => $input,
                'where' => $where,
                'to' => $to,
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
            if (null === $type && \is_string($step['raw'] ?? null)) {
                // Still unknown: nothing of what was written is lost, the key as the page has it.
                $raw = self::yamlOrText($step['raw']);
                $data[] = \is_array($raw) ? [...array_combine(array_map(strval(...), array_keys($raw)), array_values($raw)), ...$out] : $out;

                continue;
            }
            foreach (array_keys(null === $type ? [] : $type->options()) as $option) {
                $out = match ($option) {
                    // A return may give nothing.
                    'values' => StepType::Return === $type && [] === self::valuesTo($step['values'] ?? []) ? $out : $out + ['values' => self::valuesTo($step['values'] ?? [])],
                    'input' => [] === ($given = self::valuesTo($step['input'] ?? [])) ? $out : $out + ['input' => $given],
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
                    'limit', 'timeout', 'retries' => '' === self::text($step[$option] ?? '') ? $out : $out + [$option => self::typed(self::text($step[$option] ?? ''))],
                    // An email's body is a text, kept as typed; a request's, a text or YAML.
                    'body' => StepType::SendEmail === $type
                        ? ('' === self::text($step['body'] ?? '') ? $out : $out + ['body' => self::text($step['body'] ?? '')])
                        : ('' === trim(self::text($step['body'] ?? '')) ? $out : $out + ['body' => self::yamlOrText(self::text($step['body'] ?? ''))]),
                    'headers' => '' === trim(self::text($step['headers'] ?? '')) ? $out : $out + ['headers' => self::yamlOrText(self::text($step['headers'] ?? ''))],
                    'to' => $out + ['to' => self::recipientsTo($step['to'] ?? [])],
                    'link' => '' === self::text($step['link_object'] ?? '') ? $out : $out + ['link' => ['object' => self::text($step['link_object'] ?? ''), 'id' => self::text($step['link_id'] ?? '')]],
                    'fail_on_error' => false === ($step['fail_on_error'] ?? true) ? $out + ['fail_on_error' => false] : $out,
                    default => '' === self::text($step[$option] ?? '') ? $out : $out + [$option => self::text($step[$option] ?? '')],
                };
            }
            $data[] = $out;
        }

        return $data;
    }

    /**
     * The rows of a send_email's or a notify's to, those with a value.
     *
     * @return list<array<string, string>>
     */
    private static function recipientsTo(mixed $rows): array
    {
        $to = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            $kind = \is_array($row) ? self::text($row['kind'] ?? '') : '';
            $value = \is_array($row) ? trim(self::text($row['value'] ?? '')) : '';
            if (\in_array($kind, self::RECIPIENT_KINDS, true) && '' !== $value) {
                $to[] = [$kind => $value];
            }
        }

        return $to;
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
        return ['field' => self::text($test['field'] ?? ''), 'op' => self::text($test['op'] ?? 'eq'), 'value' => \array_key_exists('value', $test) ? self::valueText($test['value']) : ''];
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
            $raw = WorkflowYaml::parse($condition['raw']);

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
     * A value as the editor shows it, in one line of text that typed() reads back as the same
     * value: a text as it is, unless it would read as something else ('1.50', 'true', '[a]'),
     * then quoted as YAML quotes it; null, true, false and numbers as YAML writes them; a list or
     * a map as YAML on one line.
     */
    private static function valueText(mixed $value): string
    {
        if (\is_string($value)) {
            if (self::typed($value) === $value) {
                return $value;
            }
            $quoted = Yaml::dump($value, 0);

            return \in_array($quoted[0] ?? '', ["'", '"'], true) ? $quoted : "'".str_replace("'", "''", $value)."'";
        }

        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            default => Yaml::dump($value, 0),
        };
    }

    /**
     * A text of the editor as the definition holds it. Typed: null, true, false; a number written
     * as one (100, -2, 1.5: a text with leading zeros, as a postcode, or too long for an integer
     * stays a text); a list ([a, b]) or a map ({ a: 1 }); a text in quotes ('100'), the way to
     * write one that would read as any of these. Anything else, {{ … }} paths included, a text.
     */
    private static function typed(string $text): mixed
    {
        if ('null' === $text) {
            return null;
        }
        if (\in_array($text, ['true', 'false'], true)) {
            return 'true' === $text;
        }
        if ((string) (int) $text === $text) {
            return (int) $text;
        }
        if (1 === preg_match('/^-?(0|[1-9]\d*)\.\d+$/', $text)) {
            return (float) $text;
        }
        $first = $text[0] ?? '';
        $last = substr($text, -1);
        $quoted = \strlen($text) > 1 && $first === $last && \in_array($first, ["'", '"'], true);
        $collection = ('[' === $first && ']' === $last) || ('{' === $first && '}' === $last && !str_starts_with($text, '{{'));
        if (!$quoted && !$collection) {
            return $text;
        }
        try {
            $parsed = WorkflowYaml::parse($text);
        } catch (ParseException) {
            return $text;
        }
        if ($quoted) {
            return \is_string($parsed) ? $parsed : $text;
        }
        if (!\is_array($parsed)) {
            return $text;
        }
        // A path written without quotes in a list ([{{ a.id }}, open]) reads as a map of YAML:
        // typed only when every {{ of the text ended up inside a text of the list.
        $paths = 0;
        array_walk_recursive($parsed, static function (mixed $leaf) use (&$paths): void {
            $paths += \is_string($leaf) ? substr_count($leaf, '{{') : 0;
        });

        return substr_count($text, '{{') === $paths ? $parsed : $text;
    }

    /**
     * A select's options as one line of text: separated by commas, or, when one of them holds a
     * comma (or the line would read as a list), the list as YAML.
     *
     * @param list<string> $options
     */
    private static function optionsText(array $options): string
    {
        $line = implode(', ', $options);
        foreach ($options as $option) {
            if (str_contains($option, ',') || trim($option) !== $option || '' === $option) {
                return Yaml::dump($options, 0);
            }
        }

        return str_starts_with($line, '[') ? Yaml::dump($options, 0) : $line;
    }

    /**
     * @return list<string>
     */
    private static function optionsFrom(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
            try {
                $list = WorkflowYaml::parse($line);
                if (\is_array($list)) {
                    return array_values(array_filter(array_map(self::text(...), $list), static fn (string $option): bool => '' !== $option));
                }
            } catch (ParseException) {
                // Not a list after all: read as options separated by commas.
            }
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $line)), static fn (string $option): bool => '' !== $option));
    }

    /**
     * A map or a list written as YAML ({ a: 1 }, [a, b]); anything else, a text.
     */
    private static function yamlOrText(string $text): mixed
    {
        if (str_starts_with(trim($text), '{') || str_starts_with(trim($text), '[')) {
            try {
                return WorkflowYaml::parse($text);
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
