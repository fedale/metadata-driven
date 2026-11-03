<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * Read-only view of a stored record, with PHP typed values.
 *
 * Value types per field type: text/email/url/tel/select string, integer number int,
 * decimal number numeric string, boolean bool, date \DateTimeImmutable, relation the id
 * (string), many-to-many the linked ids (list<string>, empty for none; oldest link first, then
 * by id: the order carries no meaning).
 */
final readonly class RecordOutput
{
    /**
     * @param array<string, mixed> $values keyed by field name, in field order; write-only fields (passwords) are left out
     */
    public function __construct(
        public string $id,
        public string $objectName,
        public array $values,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public function get(string $fieldName): mixed
    {
        return $this->values[$fieldName] ?? null;
    }
}
