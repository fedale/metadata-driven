<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * Who wins each conflict of an upgrade: a strategy for all of them (the console's --strategy),
 * and, item by item, the choices made in the admin. A conflict nothing decides stops the upgrade.
 */
final readonly class Resolution
{
    /**
     * @param array<string, Strategy> $items by the conflict's key (ConflictView::$key)
     */
    public function __construct(
        private ?Strategy $default = null,
        private array $items = [],
    ) {
    }

    public function for(string $key): ?Strategy
    {
        return $this->items[$key] ?? $this->default;
    }
}
