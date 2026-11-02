<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
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

    /**
     * Renames what a renamed object or field renames: the table, the columns, and the indexes
     * and constraints named after them, so that the table matches the new definition. The
     * records stay where they are.
     *
     * @param ObjectDefinitionDto $before the object as it was (its fields and archived fields)
     * @param ObjectDefinitionDto $after  the object as it is now, fields paired by id
     */
    public function rename(ObjectDefinitionDto $before, ObjectDefinitionDto $after): SchemaPlan;

    /**
     * Drops the table of a purged object, its records with it.
     */
    public function dropTable(ObjectDefinitionDto $object): SchemaPlan;

    /**
     * Drops the column of a purged field, its values with it.
     */
    public function dropColumn(ObjectDefinitionDto $object, FieldDefinitionDto $field): SchemaPlan;
}
