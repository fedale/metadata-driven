<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use App\Module\Record\Contract\Event\RecordWriteAction;

final readonly class Trigger
{
    /**
     * @param list<string> $fields on update: run only when one of these changed; empty for any
     */
    public function __construct(
        public TriggerEvent $on,
        public string $object,
        public Timing $when,
        public array $fields,
    ) {
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
