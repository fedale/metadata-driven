<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Record\Contract\Dto\RecordActionLink;

/**
 * What other modules offer to do on a record, shown on its page (the workflows a user may run on
 * it): the Record module shows them, knowing nothing of what they do. Implementations are tagged
 * "app.record_actions" automatically.
 */
interface RecordActionsInterface
{
    /**
     * The actions the current user may take on the record, its read already checked.
     *
     * @return list<RecordActionLink>
     */
    public function actionsFor(string $objectName, string $recordId): array;

    /**
     * The actions the current user may take on a selection of the object's records, as buttons
     * of a grid's bulk bar: their url gets the ids selected (ids[]), or all=1 and the grid's
     * filters (RecordSelectionInterface tells the records back). The grid of a view other than
     * the default one is named.
     *
     * @return list<RecordActionLink>
     */
    public function selectionActionsFor(string $objectName, ?string $gridName): array;
}
