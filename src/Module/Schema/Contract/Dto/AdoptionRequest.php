<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * A table to adopt as an object, as the admin chose: the object's name and labels, and the field
 * each column becomes (null: left out). Its key is its primary key's.
 */
final readonly class AdoptionRequest
{
    /**
     * @param array<string, ColumnMapping|null> $columns by column name, every column but the key
     */
    public function __construct(
        public string $table,
        public string $objectName,
        public string $labelSingular,
        public string $labelPlural,
        public array $columns,
    ) {
    }
}
