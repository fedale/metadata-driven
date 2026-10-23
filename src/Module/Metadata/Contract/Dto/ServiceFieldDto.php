<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A field of a composite form that belongs to no object: it is validated with the rules of
 * its type, then dropped (e.g. "I accept the terms"). A required boolean must be checked.
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
    ) {
    }
}
