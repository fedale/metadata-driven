<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * A conflict as the admin shows it: what it is, this instance's version and the new one.
 */
final readonly class ConflictView
{
    public function __construct(
        /** What a Resolution names it by. */
        public string $key,
        public string $description,
        /** This instance's version, as YAML; null when it no longer has the item. */
        public ?string $here = null,
        /** The new version's, as YAML; null when the new version removes the item. */
        public ?string $there = null,
    ) {
    }
}
