<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * The field a column of an adopted table becomes.
 */
final readonly class ColumnMapping
{
    /**
     * @param array<string, mixed> $settings of the field type, the target of a relation by object name
     */
    public function __construct(
        public string $fieldName,
        public string $label,
        public string $type,
        public array $settings = [],
        public bool $required = false,
        public bool $unique = false,
        // A relation to a table adopted with this one: its target is the object that table
        // becomes, whatever it is named ("target" in the settings is ignored then).
        public ?string $targetTable = null,
    ) {
    }

    public function withNames(string $fieldName, string $label): self
    {
        return new self($fieldName, $label, $this->type, $this->settings, $this->required, $this->unique, $this->targetTable);
    }
}
