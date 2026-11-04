<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

/**
 * Port implemented by the module owning the records: before a set of fields becomes unique
 * together, the records already breaking the rule are counted, so that the change is refused
 * with a readable message instead of failing on the unique index.
 */
interface DuplicateRecordsInterface
{
    /**
     * @param list<string> $fields field names of the object, each storing a column
     *
     * @return int how many live records share the values of every field with another live
     *             record; a record with one of the fields empty never counts
     */
    public function count(string $objectName, array $fields): int;
}
