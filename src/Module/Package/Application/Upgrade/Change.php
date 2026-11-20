<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Upgrade;

use App\Shared\Message\Message;
use Fedale\PackageFormat\Versions\ItemState;

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

    /**
     * "objects/product.yaml: add object acme.product", to read in the user's language.
     */
    public function describe(): Message
    {
        $state = $this->state();
        $parameters = ['path' => $state->document->path, 'kind' => self::kind($state->kind), 'id' => $state->id];

        return match ($this->action) {
            ChangeAction::Add => new Message('package.upgrade.change.add', '%path%: add %kind% %id%', $parameters),
            ChangeAction::Change => new Message('package.upgrade.change.change', '%path%: change %kind% %id%', $parameters),
            ChangeAction::Remove => new Message('package.upgrade.change.remove', '%path%: remove %kind% %id%', $parameters),
        };
    }

    /**
     * The item, "field acme.product.sku": where a refusal of the app is reported.
     */
    public function item(): Message
    {
        return self::itemOf($this->state()->kind, $this->state()->id);
    }

    public static function itemOf(string $kind, string $id): Message
    {
        return new Message('package.upgrade.item', '%kind% %id%', ['kind' => self::kind($kind), 'id' => $id]);
    }

    /**
     * The kind of an item (ItemState::$kind) as a word to translate; one this module does not
     * know stays as it is.
     */
    public static function kind(string $kind): string|Message
    {
        return match ($kind) {
            'object' => new Message('package.upgrade.kind.object', 'object'),
            'field' => new Message('package.upgrade.kind.field', 'field'),
            'view' => new Message('package.upgrade.kind.view', 'view'),
            'form' => new Message('package.upgrade.kind.form', 'form'),
            'translation' => new Message('package.upgrade.kind.translation', 'translation'),
            'workflow' => new Message('package.upgrade.kind.workflow', 'workflow'),
            default => $kind,
        };
    }
}
