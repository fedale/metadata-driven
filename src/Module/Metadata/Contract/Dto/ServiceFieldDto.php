<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A field of a composite form that belongs to no object: it is validated with the rules of
 * its type, then dropped (e.g. "I accept the terms"). A required boolean must be checked.
 * "ask" tells whether it is asked on every save of its record, or only when it is created.
 */
final readonly class ServiceFieldDto
{
    /**
     * @param array<string, mixed> $settings resolved with the type defaults
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public bool $isRequired,
        public array $settings,
        public ServiceFieldAsk $ask = ServiceFieldAsk::Always,
    ) {
    }

    /**
     * Whether the form asks this field for a record: a new one (no id yet), or an existing one.
     */
    public function isAskedFor(bool $isNew): bool
    {
        return $isNew || ServiceFieldAsk::Always === $this->ask;
    }
}
