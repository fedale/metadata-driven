<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * Dynamic DTO carrying raw values for a record write, as received from a form or an API.
 *
 * Only the provided fields are present: on update a missing field is left untouched,
 * while a field set to null is cleared.
 */
final readonly class RecordInput
{
    /**
     * @param array<string, mixed> $values raw values keyed by field name
     */
    public function __construct(
        public string $objectName,
        public array $values,
    ) {
    }

    public function has(string $fieldName): bool
    {
        return \array_key_exists($fieldName, $this->values);
    }
}
