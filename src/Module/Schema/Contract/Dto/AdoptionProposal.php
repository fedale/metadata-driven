<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

use App\Module\Metadata\Contract\Dto\KeyType;

/**
 * How a table would become an object, as proposed from its catalog: the object's name, labels
 * and key, and the field each column would become. The admin changes it before adopting.
 */
final readonly class AdoptionProposal
{
    /**
     * @param array<string, ColumnProposal> $columns by column name, the key column left out
     */
    public function __construct(
        public DatabaseTable $table,
        public string $objectName,
        public string $labelSingular,
        public string $labelPlural,
        public KeyType $keyType,
        public array $columns,
    ) {
    }

    /**
     * The adoption as proposed, unchanged.
     */
    public function request(): AdoptionRequest
    {
        return new AdoptionRequest(
            $this->table->name,
            $this->objectName,
            $this->labelSingular,
            $this->labelPlural,
            array_map(static fn (ColumnProposal $column): ?ColumnMapping => $column->mapping(), $this->columns),
        );
    }
}
