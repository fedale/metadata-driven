<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * A record read through a form, with the records of its sections.
 */
final readonly class FormRecord
{
    /**
     * @param array<string, FormRecord|list<FormRecord>|null> $sections keyed by section name: a record or null
     *                                                                  for a one-to-one section, the rows for a one-to-many one
     */
    public function __construct(
        public RecordOutput $record,
        public array $sections,
    ) {
    }
}
