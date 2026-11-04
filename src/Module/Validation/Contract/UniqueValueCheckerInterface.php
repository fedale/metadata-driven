<?php

declare(strict_types=1);

namespace App\Module\Validation\Contract;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * Port implemented by the module owning record storage.
 *
 * The check only produces a readable error early: the database unique index stays
 * the authority against concurrent writes.
 */
interface UniqueValueCheckerInterface
{
    public function isTaken(ObjectDefinitionDto $object, FieldDefinitionDto $field, mixed $value, ?string $exceptRecordId = null): bool;

    /**
     * Whether another live record holds the same values in every field of a set unique
     * together. A field missing from $values keeps the value the record $exceptRecordId has
     * (an update carries the fields that change only); an empty value never matches.
     *
     * @param list<string>         $fields the set, field names of the object
     * @param array<string, mixed> $values typed values, by field name
     */
    public function isTakenTogether(ObjectDefinitionDto $object, array $fields, array $values, ?string $exceptRecordId = null): bool;
}
