<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * The raw input of one node of a form: a record of the node's object, its service
 * fields, and the input of its sections.
 *
 * A one-to-one section holds a node or null; a one-to-many section a list of rows, keyed as
 * submitted (the keys name the rows in the errors). A row with an id updates that record, a
 * row without one creates a record; the existing records missing from the rows are deleted.
 */
final readonly class FormNodeInput
{
    /**
     * @param array<string, mixed>                                               $values   raw values keyed by field name
     * @param array<string, mixed>                                               $service  raw values of the service fields
     * @param array<string, FormNodeInput|array<int|string, FormNodeInput>|null> $sections keyed by section name
     */
    public function __construct(
        public ?string $id = null,
        public array $values = [],
        public array $service = [],
        public array $sections = [],
    ) {
    }
}
