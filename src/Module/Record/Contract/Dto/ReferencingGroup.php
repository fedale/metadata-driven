<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * The inverse (one-to-many) side of a relation, for one record: the records of
 * $objectName whose $fieldName points at it.
 */
final readonly class ReferencingGroup
{
    /**
     * @param list<RecordLabel> $records the first ones, by label
     * @param int               $total   how many there are in all
     */
    public function __construct(
        public string $objectName,
        public string $fieldName,
        public string $label,
        public array $records,
        public int $total,
    ) {
    }
}
