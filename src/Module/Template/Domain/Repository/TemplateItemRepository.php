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
}
