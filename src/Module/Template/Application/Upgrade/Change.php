<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * One item an upgrade adds, changes or removes, with its three states.
 */
final readonly class Change
{
    public function __construct(
        public ChangeAction $action,
        /** The installed version of the item; null for an addition. */
        public ?ItemState $base,
        /** The new version; null for a removal. */
        public ?ItemState $theirs,
        /** What the instance holds now; null when it no longer has it. */
        public ?ItemState $mine,
        /** The instance changed it too, differently. */
        public bool $conflict,
    ) {
    }

    public function state(): ItemState
    {
        return $this->theirs ?? $this->base ?? throw new \LogicException('A change has a state.');
    }

    /**
     * What a Resolution names the item by: "field:acme.product.sku".
     */
    public function key(): string
    {
        return $this->state()->key();
    }

    public function describe(): string
    {
        $state = $this->state();

        return \sprintf('%s: %s %s %s', $state->document->path, $this->action->value, $state->kind, $state->id);
    }
}
