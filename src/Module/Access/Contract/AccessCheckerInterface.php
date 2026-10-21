<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * What the signed-in user may do. Anonymous users may do nothing.
 */
interface AccessCheckerInterface
{
    public function canRecords(string $objectName, RecordAction $action): bool;

    public function canAdminister(): bool;
}
