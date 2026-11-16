<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use App\Module\Record\Contract\Event\RecordWriteAction;

final readonly class Trigger
{
    /**
     * @param list<string>    $fields    on update: run only when one of these changed; empty for any
     * @param string|null     $label     a manual trigger's button
     * @param list<FormInput> $form      a manual trigger's inputs, asked before it runs
     * @param string|null     $selection a manual trigger's run on a grid's selection: each (a run
     *                                   per record) or all (one run on them all); null for none
     */
    public function __construct(
        public TriggerEvent $on,
        public string $object,
        public Timing $when,
        public array $fields,
        public ?string $label = null,
        public array $form = [],
        public ?string $selection = null,
    ) {
    }

    /**
     * One run on a grid's selection: $trigger.records, no $trigger.record.
     */
    public function runsOnAll(): bool
    {
        return 'all' === $this->selection;
    }

    public function isManual(): bool
    {
        return TriggerEvent::Manual === $this->on;
    }

    public function input(string $name): ?FormInput
    {
        foreach ($this->form as $input) {
            if ($name === $input->name) {
                return $input;
            }
        }

        return null;
    }

    /**
     * Whether a write starts it.
     *
     * @param list<string> $changed the fields the write changes
     */
    public function fires(string $object, RecordWriteAction $action, array $changed): bool
    {
        if ($object !== $this->object || !$this->on->matches($action)) {
            return false;
        }

        return RecordWriteAction::Update !== $action || [] === $this->fields || [] !== array_intersect($this->fields, $changed);
    }
}
