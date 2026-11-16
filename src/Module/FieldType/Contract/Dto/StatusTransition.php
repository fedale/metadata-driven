<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * A transition of a Status field: from one of its states to another, taken by whoever holds one
 * of its roles (any role: none listed).
 */
final readonly class StatusTransition
{
    /**
     * @param list<string> $from
     * @param list<string> $roles
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $from,
        public string $to,
        public array $roles,
    ) {
    }
}
