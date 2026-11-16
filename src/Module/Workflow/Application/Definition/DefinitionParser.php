<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a workflow's definition, YAML as written or the array stored, and checks its structure:
 * the keys, the types, the step types and their options, the conditions, the {{ … }} paths as
 * paths. What it names (objects, fields, roles, the steps a path reads) is DefinitionValidator's.
 *
 * Every problem is collected, located by its path, and thrown at once.
 */
final class DefinitionParser
{
    public const int MAX_STEPS = 50;

    /** A path on the data chain: a root ($trigger, or a step's key), then names. */
    public const string PATH = '\$?[a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*';

    public const string TEMPLATE = '/\{\{\s*('.self::PATH.')\s*\}\}/';

    private const string KEY = '/^[a-z][a-z0-9_]{0,49}$/';

    private const string ROLE = '/^ROLE_[A-Z0-9_]{1,59}$/';

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $keys = [];

    private int $count = 0;

    /** The definition read has a manual trigger: no write for set_values to change. */
    private bool $manual = false;

    /**
     * @throws InvalidWorkflowDefinition
     */
    public function parse(string $yaml): Definition
    {
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new InvalidWorkflowDefinition(['' => [\sprintf('Not valid YAML: %s', $e->getMessage())]]);
        }

        return $this->fromArray(\is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidWorkflowDefinition
     */
    public function fromArray(array $data): Definition
    {
        [$this->errors, $this->keys, $this->count] = [[], [], 0];
        $this->manual = \is_array($data['trigger'] ?? null) && 'manual' === ($data['trigger']['on'] ?? null);

        $this->unknownKeys($data, ['trigger', 'steps'], '');
        $trigger = $this->trigger($data['trigger'] ?? null);
        $steps = $this->steps($data['steps'] ?? null, 'steps', $trigger?->when);
        if ($this->count > self::MAX_STEPS) {
            $this->error('steps', \sprintf('At most %d steps, branches included: there are %d.', self::MAX_STEPS, $this->count));
        }

        if ([] !== $this->errors || null === $trigger) {
            throw new InvalidWorkflowDefinition($this->errors);
        }

        return new Definition($trigger, $steps);
    }

    private function trigger(mixed $trigger): ?Trigger
    {
        if (!\is_array($trigger)) {
            $this->error('trigger', 'Required: a map with on, object and when.');

            return null;
        }
        $on = \is_string($trigger['on'] ?? null) ? TriggerEvent::tryFrom($trigger['on']) : null;
        if (null === $on) {
            $this->error('trigger.on', \sprintf('One of %s.', implode(', ', array_column(TriggerEvent::cases(), 'value'))));
        }
        if (TriggerEvent::Manual === $on) {
            return $this->manualTrigger($trigger);
        }
        $this->unknownKeys($trigger, ['on', 'object', 'when', 'fields'], 'trigger');
        $object = $this->name($trigger['object'] ?? null, 'trigger.object');
        $when = \is_string($trigger['when'] ?? null) ? Timing::tryFrom($trigger['when']) : null;
        if (null === $when) {
            $this->error('trigger.when', 'before or after.');
        }
        $fields = [];
        if (\array_key_exists('fields', $trigger)) {
            if (!\in_array($on, [TriggerEvent::Updated, TriggerEvent::CreatedOrUpdated], true)) {
                $this->error('trigger.fields', 'Only for a trigger on updated or created_or_updated: the fields whose change runs it.');
            }
            $fields = $this->names($trigger['fields'], 'trigger.fields');
        }

        return null === $on || null === $object || null === $when ? null : new Trigger($on, $object, $when, $fields);
    }

    /**
     * A manual trigger: run by someone, as an after-run, its button's label and its inputs.
     *
     * @param array<mixed> $trigger
     */
    private function manualTrigger(array $trigger): ?Trigger
    {
        $this->unknownKeys($trigger, ['on', 'object', 'label', 'selection', 'form'], 'trigger');
        $selection = $trigger['selection'] ?? null;
        if (null !== $selection && !\in_array($selection, ['each', 'all'], true)) {
            $this->error('trigger.selection', 'each (a run per record) or all (one run on them all).');
            $selection = null;
        }
        $object = $this->name($trigger['object'] ?? null, 'trigger.object');
        $label = \is_string($trigger['label'] ?? null) && '' !== trim($trigger['label']) && mb_strlen($trigger['label']) <= 100 ? trim($trigger['label']) : null;
        if (null === $label) {
            $this->error('trigger.label', 'Required: the text of its button, at most 100 characters.');
        }
        $form = [];
        if (\array_key_exists('form', $trigger)) {
            if (!\is_array($trigger['form']) || !array_is_list($trigger['form'])) {
                $this->error('trigger.form', 'A list of inputs, each with name, label and type.');
            } else {
                $names = [];
                foreach ($trigger['form'] as $i => $input) {
                    $input = $this->formInput($input, 'trigger.form.'.$i);
                    if (null !== $input && isset($names[$input->name])) {
                        $this->error('trigger.form.'.$i.'.name', \sprintf('"%s" names another input already.', $input->name));
                    } elseif (null !== $input) {
                        $names[$input->name] = true;
                        $form[] = $input;
                    }
                }
            }
        }

        return null === $object || null === $label ? null : new Trigger(TriggerEvent::Manual, $object, Timing::After, [], $label, $form, $selection);
    }

    private function formInput(mixed $input, string $path): ?FormInput
    {
        if (!\is_array($input)) {
            $this->error($path, 'An input is a map with name, label and type.');

            return null;
        }
        $this->unknownKeys($input, ['name', 'label', 'type', 'required', 'options'], $path);
        $name = $this->name($input['name'] ?? null, $path.'.name');
        $label = \is_string($input['label'] ?? null) && '' !== trim($input['label']) ? trim($input['label']) : null;
        if (null === $label) {
            $this->error($path.'.label', 'Required: the text shown next to it.');
        }
        $type = $input['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, FormInput::TYPES, true)) {
            $this->error($path.'.type', \sprintf('One of %s.', implode(', ', FormInput::TYPES)));
            $type = null;
        }
        $options = [];
        if ('select' === $type) {
            $raw = $input['options'] ?? null;
            if (!\is_array($raw) || !array_is_list($raw) || [] === $raw || [] !== array_filter($raw, static fn (mixed $option): bool => !\is_scalar($option))) {
                $this->error($path.'.options', 'A select takes a list of options, one at least.');
            } else {
                $options = array_map(static fn (mixed $option): string => \is_scalar($option) ? (string) $option : '', $raw);
            }
        } elseif (\array_key_exists('options', $input)) {
            $this->error($path.'.options', 'Only a select takes options.');
        }
        $required = $input['required'] ?? false;
        if (!\is_bool($required)) {
            $this->error($path.'.required', 'true or false.');
        }

        return null === $name || null === $label || null === $type ? null : new FormInput($name, $label, $type, true === $required, $options);
    }

    /**
     * @return list<Step>
     */
    private function steps(mixed $steps, string $path, ?Timing $timing): array
    {
        if (!\is_array($steps) || !array_is_list($steps) || [] === $steps) {
            $this->error($path, 'Required: a list of steps, one at least.');

            return [];
        }
        $parsed = [];
        foreach ($steps as $i => $step) {
            $step = $this->step($step, $path.'.'.$i, $timing);
            if (null !== $step) {
                $parsed[] = $step;
            }
        }

        return $parsed;
    }

    private function step(mixed $step, string $path, ?Timing $timing): ?Step
    {
        ++$this->count;
        if (!\is_array($step)) {
            $this->error($path, 'A step is a map with key, type and its options.');

            return null;
        }
        $key = \is_string($step['key'] ?? null) ? $step['key'] : null;
        if (null === $key || 1 !== preg_match(self::KEY, $key)) {
            $this->error($path.'.key', 'Required: lowercase letters, digits and underscores, starting with a letter; it names the step\'s output.');
            $key = null;
        } elseif (isset($this->keys[$key])) {
            $this->error($path.'.key', \sprintf('"%s" names another step already.', $key));
        } else {
            $this->keys[$key] = true;
        }
        $type = \is_string($step['type'] ?? null) ? StepType::tryFrom($step['type']) : null;
        if (null === $type) {
            $this->error($path.'.type', \sprintf('One of %s.', implode(', ', array_column(StepType::cases(), 'value'))));

            return null;
        }
        $this->unknownKeys($step, ['key', 'type', ...array_keys($type->options())], $path);
        foreach ($type->options() as $option => $required) {
            if ($required && !\array_key_exists($option, $step)) {
                $this->error($path.'.'.$option, 'Required.');
            }
        }
        if ((Timing::After === $timing || $this->manual) && StepType::SetValues === $type) {
            $this->error($path.'.type', 'set_values changes the values being written: only in a workflow run before the write.');
        }

        $options = [];
        $branches = [];
        $steps = [];
        $when = null;
        foreach ($type->options() as $option => $required) {
            if (!\array_key_exists($option, $step)) {
                continue;
            }
            $value = $step[$option];
            $at = $path.'.'.$option;
            match ($option) {
                'branches' => $branches = $this->branches($value, $at, $timing),
                'when' => $when = $this->condition($value, $at),
                'object', 'match' => $options[$option] = $this->name($value, $at),
                'id', 'message' => $options[$option] = $this->text($value, $at),
                'values' => $options[$option] = $this->values($value, $at),
                'act_as' => $options[$option] = $this->role($value, $at),
                'emit_events' => $options[$option] = $this->flag($value, $at),
                'where' => $options[$option] = $this->where($value, $at),
                'sort' => $options[$option] = 'id' === $value ? 'id' : $this->name($value, $at),
                'direction' => $options[$option] = \in_array($value, ['asc', 'desc'], true) ? $value : $this->error($at, 'asc or desc.'),
                'limit' => $options[$option] = \is_int($value) && $value > 0 ? $value : $this->error($at, 'A number of records, one at least.'),
                'items' => $options[$option] = \is_string($value) && 1 === preg_match('/^\{\{\s*'.self::PATH.'\s*\}\}$/', $value) ? $value : $this->error($at, 'One {{ path }} to a list, e.g. {{ open.records }}.'),
                'steps' => $steps = $this->steps($value, $at, $timing),
                default => null,
            };
        }

        return null === $key ? null : new Step($key, $type, array_filter($options, static fn (mixed $value): bool => null !== $value), $branches, $when, $steps);
    }

    /**
     * @return list<Branch>
     */
    private function branches(mixed $branches, string $path, ?Timing $timing): array
    {
        if (!\is_array($branches) || !array_is_list($branches) || [] === $branches) {
            $this->error($path, 'Required: a list of branches, each with when (none for the last, the else) and steps.');

            return [];
        }
        $parsed = [];
        $last = \count($branches) - 1;
        foreach ($branches as $i => $branch) {
            $at = $path.'.'.$i;
            if (!\is_array($branch)) {
                $this->error($at, 'A branch is a map with when and steps.');
                continue;
            }
            $this->unknownKeys($branch, ['when', 'steps'], $at);
            $when = null;
            if (\array_key_exists('when', $branch)) {
                $when = $this->condition($branch['when'], $at.'.when');
            } elseif ($i !== $last) {
                $this->error($at.'.when', 'Required: only the last branch, the else, goes without.');
            }
            $parsed[] = new Branch($when, $this->steps($branch['steps'] ?? null, $at.'.steps', $timing));
        }

        return $parsed;
    }

    private function condition(mixed $condition, string $path): ?Condition
    {
        if (!\is_array($condition)) {
            $this->error($path, 'A condition: { field, op, value }, or { all: [...] } or { any: [...] }.');

            return null;
        }
        foreach (['all', 'any'] as $group) {
            if (\array_key_exists($group, $condition)) {
                $this->unknownKeys($condition, [$group], $path);
                $conditions = $condition[$group];
                if (!\is_array($conditions) || !array_is_list($conditions) || [] === $conditions) {
                    $this->error($path.'.'.$group, 'A list of conditions, one at least.');

                    return null;
                }
                $parsed = [];
                $broken = false;
                foreach ($conditions as $i => $nested) {
                    $nested = $this->condition($nested, $path.'.'.$group.'.'.$i);
                    null === $nested ? $broken = true : $parsed[] = $nested;
                }

                return $broken ? null : Condition::group($group, $parsed);
            }
        }
        $this->unknownKeys($condition, ['field', 'op', 'value'], $path);
        $field = $condition['field'] ?? null;
        if (!\is_string($field) || 1 !== preg_match('/^'.self::PATH.'$/', $field)) {
            $this->error($path.'.field', 'Required: a path on the data chain, e.g. $trigger.record.status.');
            $field = null;
        }
        $operator = $condition['op'] ?? null;
        if (!\is_string($operator) || !\in_array($operator, Condition::OPERATORS, true)) {
            $this->error($path.'.op', \sprintf('One of %s.', implode(', ', Condition::OPERATORS)));

            return null;
        }
        $value = $condition['value'] ?? null;
        if (\in_array($operator, Condition::UNARY, true)) {
            if (\array_key_exists('value', $condition)) {
                $this->error($path.'.value', \sprintf('%s takes no value.', $operator));
            }
        } elseif (!\array_key_exists('value', $condition)) {
            $this->error($path.'.value', 'Required.');
        } elseif (\in_array($operator, ['in', 'between'], true) && (!\is_array($value) || !array_is_list($value) || ('between' === $operator && 2 !== \count($value)))) {
            $this->error($path.'.value', 'between' === $operator ? 'A list of two values: the lowest and the highest.' : 'A list of values.');
        } else {
            $this->templates($value, $path.'.value');
        }

        return null === $field ? null : Condition::test($field, $operator, $value);
    }

    /**
     * A search's tests: on fields of the object searched, all of which must hold.
     *
     * @return list<array<string, mixed>>
     */
    private function where(mixed $where, string $path): array
    {
        if (!\is_array($where) || !array_is_list($where) || [] === $where) {
            $this->error($path, 'A list of tests, { field, op, value }, all of which must hold.');

            return [];
        }
        $tests = [];
        foreach ($where as $i => $test) {
            $at = $path.'.'.$i;
            if (!\is_array($test)) {
                $this->error($at, 'A test: { field, op, value }.');
                continue;
            }
            $this->unknownKeys($test, ['field', 'op', 'value'], $at);
            $field = 'id' === ($test['field'] ?? null) ? 'id' : $this->name($test['field'] ?? null, $at.'.field');
            $operator = $test['op'] ?? null;
            if (!\is_string($operator) || !\in_array($operator, Condition::OPERATORS, true)) {
                $this->error($at.'.op', \sprintf('One of %s.', implode(', ', Condition::OPERATORS)));
                continue;
            }
            if (\in_array($operator, Condition::UNARY, true)) {
                if (\array_key_exists('value', $test)) {
                    $this->error($at.'.value', \sprintf('%s takes no value.', $operator));
                }
                null === $field ?: $tests[] = ['field' => $field, 'op' => $operator];
                continue;
            }
            $value = $test['value'] ?? null;
            if (!\array_key_exists('value', $test)) {
                $this->error($at.'.value', 'Required.');
            } elseif (\in_array($operator, ['in', 'between'], true) && (!\is_array($value) || !array_is_list($value) || ('between' === $operator && 2 !== \count($value)))) {
                $this->error($at.'.value', 'between' === $operator ? 'A list of two values: the lowest and the highest.' : 'A list of values.');
            } else {
                $this->templates($value, $at.'.value');
            }
            null === $field ?: $tests[] = ['field' => $field, 'op' => $operator, 'value' => $value];
        }

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(mixed $values, string $path): array
    {
        if (!\is_array($values) || (array_is_list($values) && [] !== $values) || [] === $values) {
            $this->error($path, 'A map of field names to values, one at least.');

            return [];
        }
        $parsed = [];
        foreach ($values as $field => $value) {
            $field = (string) $field;
            if (null === $this->name($field, $path.'.'.$field)) {
                continue;
            }
            $this->templates($value, $path.'.'.$field);
            $parsed[$field] = $value;
        }

        return $parsed;
    }

    /**
     * Checks the {{ … }} of a value, nested in lists and maps: each must hold a path.
     */
    private function templates(mixed $value, string $path): void
    {
        if (\is_array($value)) {
            foreach ($value as $key => $nested) {
                $this->templates($nested, $path.'.'.$key);
            }

            return;
        }
        if (\is_string($value) && substr_count($value, '{{') !== preg_match_all(self::TEMPLATE, $value)) {
            $this->error($path, 'Each {{ … }} holds a path on the data chain, e.g. {{ $trigger.record.name }}: nothing else.');
        }
    }

    private function name(mixed $name, string $path): ?string
    {
        if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $this->error($path, 'Required: a machine name, lowercase letters, digits and underscores.');

            return null;
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private function names(mixed $names, string $path): array
    {
        if (!\is_array($names) || !array_is_list($names) || [] === $names) {
            $this->error($path, 'A list of field names.');

            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $name, int $i): ?string => $this->name($name, $path.'.'.$i), $names, array_keys($names))));
    }

    private function text(mixed $text, string $path): ?string
    {
        if (!\is_string($text) || '' === trim($text)) {
            $this->error($path, 'Required: a text, with {{ … }} paths if needed.');

            return null;
        }
        $this->templates($text, $path);

        return $text;
    }

    private function role(mixed $role, string $path): ?string
    {
        if (!\is_string($role) || 1 !== preg_match(self::ROLE, $role)) {
            $this->error($path, 'A role of the workspace, e.g. ROLE_ACCOUNTANT.');

            return null;
        }

        return $role;
    }

    private function flag(mixed $flag, string $path): bool
    {
        if (!\is_bool($flag)) {
            $this->error($path, 'true or false.');
        }

        return true === $flag;
    }

    /**
     * @param array<mixed> $map
     * @param list<string> $allowed
     */
    private function unknownKeys(array $map, array $allowed, string $path): void
    {
        foreach (array_diff(array_map(strval(...), array_keys($map)), $allowed) as $key) {
            $this->error(ltrim($path.'.'.$key, '.'), \sprintf('Unknown: here, %s.', implode(', ', $allowed)));
        }
    }

    /**
     * @return null what an option it refuses is worth
     */
    private function error(string $path, string $message): null
    {
        $this->errors[$path][] = $message;

        return null;
    }
}
