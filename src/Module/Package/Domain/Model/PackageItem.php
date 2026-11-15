<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * The id an item of the app has in a package. An exported item keeps it from one export to the
 * next, renamed or not: that is what lets an upgrade tell a rename from a removal followed by an
 * addition. Installed packages record their items here too (phase 2).
 */
class PackageItem
{
    public function __construct(
        private Uuid $id,
        /** The package's name, vendor/name. */
        private string $package,
        private ItemKind $kind,
        /** The id in the package, e.g. "acme.product.name". */
        private string $itemId,
        /** The id of the metadata row: an object, a field, a view, a form. */
        private string $localId,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPackage(): string
    {
        return $this->package;
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
