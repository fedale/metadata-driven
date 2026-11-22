<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * A table to adopt as an object, as the admin chose: the object's name and labels, and the field
 * each column becomes (null: left out). Its key is its primary key's.
 *
 * A table linking two others says what it becomes in $junction: as a link table, it becomes no
 * object (its name, labels and columns are not read); as a junction object, it gets a key of its
 * own (an integer) and its two columns are relations among $columns.
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
        public ?JunctionMapping $junction = null,
    ) {
    }
}
