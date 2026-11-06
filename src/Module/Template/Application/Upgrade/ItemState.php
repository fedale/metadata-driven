<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use Fedale\TemplateFormat\Document;

/**
 * One item of a template in one of its three states (installed, new, local), normalized by
 * ItemStates so that equal means "the same".
 */
final readonly class ItemState
{
    /**
     * @param string               $kind     object, field, view, form, translation
     * @param string               $id       the item's id; for a translation, "<locale>:<key by ids>"
     * @param mixed                $value    what is compared
     * @param Document             $document the document it comes from
     * @param array<string, mixed> $extra    what applying it needs besides (a translation: its locale and key as written)
     */
    public function __construct(
        public string $kind,
        public string $id,
        public mixed $value,
        public Document $document,
        public array $extra = [],
    ) {
    }

    public function key(): string
    {
        return $this->kind.':'.$this->id;
    }

    /**
     * Strictly: ItemStates builds every value with its keys in one order, so "the same" is ===.
     */
    public function equals(?self $other): bool
    {
        return null !== $other && $this->value === $other->value;
    }
}
