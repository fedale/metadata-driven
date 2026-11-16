<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

/**
 * The records a grid's selection names, from the current request: the ids it lists (ids[]), or
 * with all=1 every record of the grid's current filters and search, as its export reads them.
 */
interface RecordSelectionInterface
{
    /**
     * At most $max + 1 ids, so that the caller tells a selection too big: whether the current user
     * may read them is the caller's to check.
     *
     * @param string|null $gridName a grid view other than the default one
     *
     * @return list<string>
     */
    public function selected(string $objectName, ?string $gridName, int $max): array;
}
