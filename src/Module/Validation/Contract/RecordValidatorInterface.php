<?php

declare(strict_types=1);

namespace App\Module\Validation\Contract;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * Single source of validation rules for records: every form, API or import goes through it.
 */
interface RecordValidatorInterface
{
    /**
     * @param array<string, mixed> $values   denormalized values keyed by field name; on update only the
     *                                       provided fields, on create every field (null when not provided)
     * @param string|null          $recordId id of the record being updated, null on create
     *
     * @return array<string, list<string>> error messages keyed by field name, empty when valid
     */
    public function validate(ObjectDefinitionDto $object, array $values, ?string $recordId = null): array;
}
