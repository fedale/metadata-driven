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
     * @param array<string, mixed>              $values keyed by field name, in field order; write-only fields (passwords) are left out
     * @param array<string, list<RecordOutput>> $rows   for each many-to-many field going through a junction object, its links
     *                                                  as the junction's live records, oldest first. Only the records read
     *                                                  one by one carry them (RecordFinderInterface::find() and
     *                                                  findReferencing(), the record a write returns), not latest() nor
     *                                                  the grid: $values holds the linked ids in every case
     */
    public function __construct(
        public string $id,
        public string $objectName,
        public array $values,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public array $rows = [],
    ) {
    }

    public function get(string $fieldName): mixed
    {
        return $this->values[$fieldName] ?? null;
    }

    /**
     * The rows of a many-to-many field going through a junction object (see $rows).
     *
     * @return list<RecordOutput>
     */
    public function rows(string $fieldName): array
    {
        return $this->rows[$fieldName] ?? [];
    }

    /**
     * @param array<string, list<RecordOutput>> $rows
     */
    public function withRows(array $rows): self
    {
        return new self($this->id, $this->objectName, $this->values, $this->createdAt, $this->updatedAt, $rows);
    }
}
