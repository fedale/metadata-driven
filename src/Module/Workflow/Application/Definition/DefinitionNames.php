<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * The objects and fields a workflow's definition names, found and renamed: what archiving
 * refuses while an active workflow uses it, and what a rename carries into the workflows.
 *
 * It reads the definition as an array, the YAML as written or the compiled one (both have the
 * same shape), not a parsed Definition: a draft being written is renamed too, whatever else it
 * has wrong. The places are DefinitionValidator's: the trigger's object, its fields and its
 * status field; a step's object, the keys of its values, its match, sort, transition field and
 * the fields of its search; the paths that read a record's field ($trigger.record.x,
 * $trigger.before.x, an iterator's item.x), in {{ … }} or as a condition's field; and the
 * conditions on $trigger.changed (a field's name) and $trigger.object (an object's).
 */
final class DefinitionNames
{
    /** The triggers that carry a record of their object. */
    private const array RECORD_EVENTS = ['created', 'updated', 'created_or_updated', 'deleted', 'transitioned', 'manual'];

    /** Step options DefinitionNames reads by their meaning; the others are only searched for paths. */
    private const array NAMED_OPTIONS = ['key', 'type', 'object', 'values', 'match', 'sort', 'field', 'where', 'when', 'branches', 'steps'];

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{object: string, field: string|null, at: string}> field null for the object itself
     */
    public static function references(array $data): array
    {
        $found = [];
        self::walk($data, static function (string $object, ?string $field, string $at) use (&$found): ?string {
            $found[] = ['object' => $object, 'field' => $field, 'at' => $at];

            return null;
        });

        return $found;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function renameObject(array $data, string $old, string $new): array
    {
        return self::walk($data, static fn (string $object, ?string $field): ?string => null === $field && $object === $old ? $new : null);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function renameField(array $data, string $objectName, string $old, string $new): array
    {
        return self::walk($data, static fn (string $object, ?string $field): ?string => $object === $objectName && $field === $old ? $new : null);
    }

    /**
     * Visits every name, the object's (field null) or a field of an object, and writes back what
     * the visit returns in its place (null: unchanged).
     *
     * @param array<string, mixed>                             $data
     * @param callable(string, ?string, string): (string|null) $visit
     *
     * @return array<string, mixed>
     */
    private static function walk(array $data, callable $visit): array
    {
        $trigger = \is_array($data['trigger'] ?? null) ? $data['trigger'] : [];
        $object = \in_array($trigger['on'] ?? null, self::RECORD_EVENTS, true) && \is_string($trigger['object'] ?? null) ? $trigger['object'] : null;
        if (null !== $object) {
            $trigger['object'] = $visit($object, null, 'trigger.object') ?? $object;
            if (\is_array($trigger['fields'] ?? null)) {
                foreach ($trigger['fields'] as $i => $field) {
                    if (\is_string($field)) {
                        $trigger['fields'][$i] = self::field($object, $field, 'trigger.fields.'.$i, $visit);
                    }
                }
            }
            if (\is_string($trigger['field'] ?? null)) {
                $trigger['field'] = self::field($object, $trigger['field'], 'trigger.field', $visit);
            }
            $data['trigger'] = $trigger;
        }
        if (\is_array($data['steps'] ?? null)) {
            $context = new NamesContext($object, self::itemObjects($data['steps'], $object), $visit);
            $data['steps'] = self::steps($data['steps'], 'steps', $context);
        }

        return $data;
    }

    /**
     * @param array<mixed> $steps
     *
     * @return array<mixed>
     */
    private static function steps(array $steps, string $path, NamesContext $context): array
    {
        foreach ($steps as $i => $step) {
            if (!\is_array($step)) {
                continue;
            }
            $at = $path.'.'.$i;
            $object = \is_string($step['object'] ?? null) ? $step['object'] : null;
            if (null !== $object) {
                $step['object'] = ($context->visit)($object, null, $at.'.object') ?? $object;
            }
            // set_values writes the record of the trigger.
            $target = 'set_values' === ($step['type'] ?? null) ? $context->trigger : $object;
            if (\is_array($step['values'] ?? null)) {
                $values = [];
                foreach ($step['values'] as $field => $value) {
                    $field = (string) $field;
                    $name = null === $target ? $field : self::field($target, $field, $at.'.values.'.$field, $context->visit);
                    $values[$name] = self::templates($value, $at.'.values.'.$field, $context);
                }
                $step['values'] = $values;
            }
            foreach (['match', 'sort', 'field'] as $option) {
                if (null !== $target && \is_string($step[$option] ?? null)) {
                    $step[$option] = self::field($target, $step[$option], $at.'.'.$option, $context->visit);
                }
            }
            if (\is_array($step['where'] ?? null)) {
                foreach ($step['where'] as $w => $test) {
                    if (!\is_array($test)) {
                        continue;
                    }
                    if (null !== $target && \is_string($test['field'] ?? null)) {
                        $test['field'] = self::field($target, $test['field'], $at.'.where.'.$w.'.field', $context->visit);
                    }
                    if (\array_key_exists('value', $test)) {
                        $test['value'] = self::templates($test['value'], $at.'.where.'.$w.'.value', $context);
                    }
                    $step['where'][$w] = $test;
                }
            }
            if (\is_array($step['when'] ?? null)) {
                $step['when'] = self::condition($step['when'], $at.'.when', $context);
            }
            if (\is_array($step['branches'] ?? null)) {
                foreach ($step['branches'] as $b => $branch) {
                    if (!\is_array($branch)) {
                        continue;
                    }
                    if (\is_array($branch['when'] ?? null)) {
                        $branch['when'] = self::condition($branch['when'], $at.'.branches.'.$b.'.when', $context);
                    }
                    if (\is_array($branch['steps'] ?? null)) {
                        $branch['steps'] = self::steps($branch['steps'], $at.'.branches.'.$b.'.steps', $context);
                    }
                    $step['branches'][$b] = $branch;
                }
            }
            if (\is_array($step['steps'] ?? null)) {
                $step['steps'] = self::steps($step['steps'], $at.'.steps', $context);
            }
            foreach ($step as $option => $value) {
                if (!\in_array($option, self::NAMED_OPTIONS, true)) {
                    $step[$option] = self::templates($value, $at.'.'.$option, $context);
                }
            }
            $steps[$i] = $step;
        }

        return $steps;
    }

    /**
     * @param array<mixed> $condition
     *
     * @return array<mixed>
     */
    private static function condition(array $condition, string $at, NamesContext $context): array
    {
        foreach (['all', 'any'] as $group) {
            if (\is_array($condition[$group] ?? null)) {
                foreach ($condition[$group] as $i => $nested) {
                    if (\is_array($nested)) {
                        $condition[$group][$i] = self::condition($nested, $at.'.'.$group.'.'.$i, $context);
                    }
                }

                return $condition;
            }
        }
        if (!\is_string($condition['field'] ?? null)) {
            return $condition;
        }
        $path = trim($condition['field'], " {}\t");
        $condition['field'] = str_replace($path, self::path($path, $at.'.field', $context), $condition['field']);
        if (!\array_key_exists('value', $condition)) {
            return $condition;
        }
        // The value names a field or an object: the fields a write changed, the trigger's object.
        $named = match (true) {
            '$trigger.changed' === $path && null !== $context->trigger => static fn (string $name): string => self::field((string) $context->trigger, $name, $at.'.value', $context->visit),
            '$trigger.object' === $path && null !== $context->trigger => static fn (string $name): string => ($context->visit)($name, null, $at.'.value') ?? $name,
            default => null,
        };
        $value = $condition['value'];
        $condition['value'] = match (true) {
            null !== $named && \is_string($value) => $named($value),
            null !== $named && \is_array($value) => array_map(static fn (mixed $one): mixed => \is_string($one) ? $named($one) : $one, $value),
            default => self::templates($value, $at.'.value', $context),
        };

        return $condition;
    }

    /**
     * The {{ … }} paths of a value, nested in lists and maps.
     */
    private static function templates(mixed $value, string $at, NamesContext $context): mixed
    {
        if (\is_array($value)) {
            foreach ($value as $key => $nested) {
                $value[$key] = self::templates($nested, $at.'.'.$key, $context);
            }

            return $value;
        }
        if (!\is_string($value) || !str_contains($value, '{{')) {
            return $value;
        }

        return (string) preg_replace_callback(
            DefinitionParser::TEMPLATE,
            static fn (array $match): string => str_replace($match[1], self::path($match[1], $at, $context), $match[0]),
            $value,
        );
    }

    /**
     * A path reading a record's field: the trigger's record (or its values before), an
     * iterator's item.
     */
    private static function path(string $path, string $at, NamesContext $context): string
    {
        $segments = explode('.', $path);
        if (!isset($segments[2])) {
            return $path;
        }
        $object = match (true) {
            '$trigger' === $segments[0] && \in_array($segments[1], ['record', 'before'], true) => $context->trigger,
            'item' === $segments[1] => $context->items[$segments[0]] ?? null,
            default => null,
        };
        if (null === $object) {
            return $path;
        }
        $segments[2] = self::field($object, $segments[2], $at, $context->visit);

        return implode('.', $segments);
    }

    /**
     * @param callable(string, ?string, string): (string|null) $visit
     */
    private static function field(string $object, string $field, string $at, callable $visit): string
    {
        // A record's id is no field of its object.
        return 'id' === $field ? $field : ($visit($object, $field, $at) ?? $field);
    }

    /**
     * The object of each iterator's items, when it is known: a search's records, or a
     * selection's (the trigger's object).
     *
     * @param array<mixed> $steps
     *
     * @return array<string, string> iterator key => object
     */
    private static function itemObjects(array $steps, ?string $trigger): array
    {
        $all = self::flatten($steps);
        $searches = [];
        foreach ($all as $step) {
            if ('search_records' === ($step['type'] ?? null) && \is_string($step['key'] ?? null) && \is_string($step['object'] ?? null)) {
                $searches[$step['key']] = $step['object'];
            }
        }
        $items = [];
        foreach ($all as $step) {
            if ('iterator' !== ($step['type'] ?? null) || !\is_string($step['key'] ?? null) || !\is_string($step['items'] ?? null)) {
                continue;
            }
            if (1 === preg_match('/^\{\{\s*([a-z][a-z0-9_]*)\.records\s*\}\}$/', $step['items'], $match) && isset($searches[$match[1]])) {
                $items[$step['key']] = $searches[$match[1]];
            } elseif (null !== $trigger && 1 === preg_match('/^\{\{\s*\$trigger\.records\s*\}\}$/', $step['items'])) {
                $items[$step['key']] = $trigger;
            }
        }

        return $items;
    }

    /**
     * Every step, those of the branches and the iterators included.
     *
     * @param array<mixed> $steps
     *
     * @return list<array<mixed>>
     */
    private static function flatten(array $steps): array
    {
        $all = [];
        foreach ($steps as $step) {
            if (!\is_array($step)) {
                continue;
            }
            $all[] = $step;
            foreach (\is_array($step['branches'] ?? null) ? $step['branches'] : [] as $branch) {
                if (\is_array($branch) && \is_array($branch['steps'] ?? null)) {
                    $all = [...$all, ...self::flatten($branch['steps'])];
                }
            }
            if (\is_array($step['steps'] ?? null)) {
                $all = [...$all, ...self::flatten($step['steps'])];
            }
        }

        return $all;
    }
}
