<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract;

use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Schema\Contract\Dto\SchemaPlan;

/**
 * Keeps the physical record tables in line with the metadata.
 *
 * Destructive changes (dropping columns or indexes, altering existing columns) are
 * only planned when explicitly allowed; otherwise they are reported as skipped.
 */
interface SchemaSynchronizerInterface
{
    /**
     * Computes the DDL without executing it (dry run).
     *
     * @throws ObjectDefinitionNotFound
     */
    public function plan(string $objectName, bool $allowDestructive = false): SchemaPlan;

    /**
     * Computes and executes the DDL in a transaction, and logs what was applied.
     *
     * @throws ObjectDefinitionNotFound
     */
    public function synchronize(string $objectName, bool $allowDestructive = false): SchemaPlan;
}
