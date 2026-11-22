<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * What a column of a table may become when the table is adopted: a field of one of some types,
 * or nothing (left out: the column stays in the table, unknown to the application).
 */
final readonly class ColumnProposal
{
    /**
     * @param array<string, ColumnMapping> $options  the fields it may become, by field type, the most fitting first
     * @param string|null                  $proposed the type proposed (a key of $options); null to leave it out
     */
    public function __construct(
        public DatabaseColumn $column,
        public array $options,
        public ?string $proposed,
    ) {
    }

    public function mapping(): ?ColumnMapping
    {
        return null === $this->proposed ? null : $this->options[$this->proposed] ?? null;
    }
}
