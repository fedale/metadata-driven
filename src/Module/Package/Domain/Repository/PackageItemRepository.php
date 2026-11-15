<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Repository;

use App\Module\Package\Domain\Model\PackageItem;

interface PackageItemRepository
{
    /**
     * @return list<PackageItem>
     */
    public function itemsOf(string $package): array;

    /**
     * Saves new items, all or none.
     *
     * @param list<PackageItem> $items
     */
    public function add(array $items): void;

    /**
     * Forgets the items of a package that are gone (a view or a form it removed).
     *
     * @param list<string> $localIds
     */
    public function remove(string $package, array $localIds): void;
}
