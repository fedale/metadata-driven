<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Repository;

use App\Module\Template\Domain\Model\TemplateItem;

interface TemplateItemRepository
{
    /**
     * @return list<TemplateItem>
     */
    public function itemsOf(string $template): array;

    /**
     * Saves new items, all or none.
     *
     * @param list<TemplateItem> $items
     */
    public function add(array $items): void;

    /**
     * Forgets the items of a template that are gone (a view or a form it removed).
     *
     * @param list<string> $localIds
     */
    public function remove(string $template, array $localIds): void;
}
