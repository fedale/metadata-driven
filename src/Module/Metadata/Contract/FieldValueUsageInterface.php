<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

/**
 * Port implemented by the module owning record storage, so that Metadata can refuse a change
 * that would orphan stored values (an option removed from a choice still held by records).
 */
interface FieldValueUsageInterface
{
    /**
     * How many live records of the object hold this value in the field.
     */
    public function countRecordsHolding(string $objectName, string $fieldName, string $value): int;
}
