<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * A workflow's definition, checked: its trigger and its steps.
 */
final readonly class Definition
{
    /**
     * @param list<Step> $steps
     */
    public function __construct(
        public Trigger $trigger,
        public array $steps,
    ) {
    }

    /**
     * What is stored: what DefinitionParser::fromArray() reads back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $trigger = $this->trigger->isManual()
            ? ['on' => $this->trigger->on->value, 'object' => $this->trigger->object, 'label' => $this->trigger->label]
            : ['on' => $this->trigger->on->value, 'object' => $this->trigger->object, 'when' => $this->trigger->when->value];
        if ([] !== $this->trigger->fields) {
            $trigger['fields'] = $this->trigger->fields;
        }
        if (null !== $this->trigger->selection) {
            $trigger['selection'] = $this->trigger->selection;
        }
        if ([] !== $this->trigger->form) {
            $trigger['form'] = array_map(static fn (FormInput $input): array => $input->toArray(), $this->trigger->form);
        }

        return ['trigger' => $trigger, 'steps' => self::steps($this->steps)];
    }

    /**
     * Every step, the steps of the branches included, in order.
     *
     * @param list<Step>|null $steps
     *
     * @return list<Step>
     */
    public function allSteps(?array $steps = null): array
    {
        $all = [];
        foreach ($steps ?? $this->steps as $step) {
            $all[] = $step;
            foreach ($step->branches as $branch) {
                $all = [...$all, ...$this->allSteps($branch->steps)];
            }
            $all = [...$all, ...$this->allSteps($step->steps)];
        }

        return $all;
    }

    /**
     * @param list<Step> $steps
     *
     * @return list<array<string, mixed>>
     */
    private static function steps(array $steps): array
    {
        return array_map(static function (Step $step): array {
            $array = ['key' => $step->key, 'type' => $step->type->value, ...$step->options];
            if (StepType::If === $step->type) {
                $array['branches'] = array_map(
                    static fn (Branch $branch): array => null === $branch->when ? ['steps' => self::steps($branch->steps)] : ['when' => $branch->when->toArray(), 'steps' => self::steps($branch->steps)],
                    $step->branches,
                );
            }
            if (null !== $step->when) {
                $array['when'] = $step->when->toArray();
            }
            if (StepType::Iterator === $step->type) {
                $array['steps'] = self::steps($step->steps);
            }

            return $array;
        }, $steps);
    }
}
