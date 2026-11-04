<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * Dynamic DTO carrying raw values for a record write, as received from a form or an API.
 *
 * Only the provided fields are present: on update a missing field is left untouched,
 * while a field set to null is cleared.
 *
 * A many-to-many field takes the linked ids; one going through a junction object takes its
 * rows instead, keyed as the caller likes (the keys name the rows in the errors): each one a
 * map of the junction's fields, its target among them, with the "id" of its junction record
 * when it has one. A row with an id updates that record, one without creates it, a record of
 * the junction missing from the rows is deleted.
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
