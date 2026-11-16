<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Event;

/**
 * The transition a write takes, when it takes one (RecordTransitionsInterface::apply()): its
 * Status field, its name, the state it leaves, the one it reaches.
 */
final readonly class RecordTransition
{
    public function __construct(
        public string $field,
        public string $name,
        public ?string $from,
        public string $to,
    ) {
    }

    /**
     * @return array{field: string, name: string, from: ?string, to: string}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'name' => $this->name, 'from' => $this->from, 'to' => $this->to];
    }
}
