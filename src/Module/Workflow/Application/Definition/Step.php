<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * One step of a workflow: its key (its output's name on the data chain), its type, its options
 * as written ({{ … }} paths unresolved), for an if its branches, for an iterator the steps run
 * once per item.
 */
final readonly class Step
{
    /**
     * @param array<string, mixed> $options
     * @param list<Branch>         $branches
     * @param list<Step>           $steps    an iterator's
     */
    public function __construct(
        public string $key,
        public StepType $type,
        public array $options,
        public array $branches = [],
        public ?Condition $when = null,
        public array $steps = [],
    ) {
    }

    /**
     * A search's tests, as written.
     *
     * @return list<array{field: string, op: string, value?: mixed}>
     */
    public function where(): array
    {
        $where = [];
        foreach (\is_array($this->options['where'] ?? null) ? $this->options['where'] : [] as $test) {
            if (\is_array($test) && \is_string($test['field'] ?? null) && \is_string($test['op'] ?? null)) {
                $where[] = \array_key_exists('value', $test)
                    ? ['field' => $test['field'], 'op' => $test['op'], 'value' => $test['value']]
                    : ['field' => $test['field'], 'op' => $test['op']];
            }
        }

        return $where;
    }

    public function string(string $option): ?string
    {
        $value = $this->options[$option] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = $this->options['values'] ?? [];

        return \is_array($values) ? array_combine(array_map(strval(...), array_keys($values)), array_values($values)) : [];
    }

    /**
     * A run_workflow's input: the called workflow's inputs to values, as written.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        $input = $this->options['input'] ?? [];

        return \is_array($input) ? array_combine(array_map(strval(...), array_keys($input)), array_values($input)) : [];
    }

    public function actAs(): ?string
    {
        return $this->string('act_as');
    }

    public function emitsEvents(): bool
    {
        return true === ($this->options['emit_events'] ?? false);
    }
}
