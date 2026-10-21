<?php

declare(strict_types=1);

namespace App\Module\Schema\Domain\Repository;

use App\Module\Schema\Domain\Model\SchemaChangeLog;

interface SchemaChangeLogRepository
{
    public function add(SchemaChangeLog $log): void;

    /**
     * @return list<SchemaChangeLog> most recent first
     */
    public function findByObjectName(string $objectName): array;
}
