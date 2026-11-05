<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * The id an item of the app has in a template. An exported item keeps it from one export to the
 * next, renamed or not: that is what lets an upgrade tell a rename from a removal followed by an
 * addition. Installed templates record their items here too (phase 2).
 */
class TemplateItem
{
    public function __construct(
        private Uuid $id,
        /** The template's name, vendor/name. */
        private string $template,
        private ItemKind $kind,
        /** The id in the template, e.g. "acme.product.name". */
        private string $itemId,
        /** The id of the metadata row: an object, a field, a view, a form. */
        private string $localId,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getKind(): ItemKind
    {
        return $this->kind;
    }

    public function getItemId(): string
    {
        return $this->itemId;
    }

    public function getLocalId(): string
    {
        return $this->localId;
    }
}
