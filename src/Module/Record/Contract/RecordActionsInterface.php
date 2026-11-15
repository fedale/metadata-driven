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
}
