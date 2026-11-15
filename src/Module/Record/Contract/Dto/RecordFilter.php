<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * A test on a field of the records searched (RecordFinderInterface::search()): its operator one
 * of OPERATORS, its value checked against the field's type.
 */
final readonly class RecordFilter
{
    public const array OPERATORS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'contains', 'not_contains', 'starts_with', 'ends_with', 'in', 'between', 'empty', 'not_empty'];

    /**
     * @param string $field a field name, or "id"
     * @param mixed  $value none for empty and not_empty; a list for in, two values for between
     */
    public function __construct(
        public string $field,
        public string $operator,
        public mixed $value = null,
    ) {
    }
}
