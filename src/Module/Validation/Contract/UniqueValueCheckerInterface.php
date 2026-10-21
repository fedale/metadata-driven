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
}
