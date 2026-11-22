<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * What a table linking two others (its primary key made of two foreign keys) becomes: a
 * many-to-many field on the object its source column points at, to the object its target column
 * points at; its rows the field's links, in the field's own link table, or the records of a
 * junction object the table becomes (asObject: its other columns kept as fields).
 */
final readonly class JunctionMapping
{
    public function __construct(
        public string $sourceColumn,
        public string $targetColumn,
        // The many-to-many field, on the source's object.
        public string $fieldName,
        public string $label,
        public bool $asObject,
    ) {
    }
}
