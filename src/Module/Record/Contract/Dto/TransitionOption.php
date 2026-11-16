<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * A transition a user may take on a record, now: its Status field, its name and label, the state
 * it reaches.
 */
final readonly class TransitionOption
{
    public function __construct(
        public string $field,
        public string $name,
        public string $label,
        public string $to,
    ) {
    }
}
