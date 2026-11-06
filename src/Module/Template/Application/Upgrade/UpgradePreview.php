<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * What an upgrade would do, computed without changing anything: the changes it would apply,
 * the conflicts to resolve first, and what refuses it outright.
 */
final readonly class UpgradePreview
{
    /**
     * @param list<string>       $changes   the changes no one disputes
     * @param list<ConflictView> $conflicts
     * @param list<string>       $refusals  nothing can be upgraded while there is one
     */
    public function __construct(
        public string $name,
        public string $from,
        public string $to,
        public array $changes,
        public array $conflicts,
        public array $refusals,
    ) {
    }
}
