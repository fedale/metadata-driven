<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * A foreign key of a table loaded into the data schema.
 */
final readonly class DatabaseForeignKey
{
    /**
     * @param list<string> $columns           the columns of the table, in the order of the key
     * @param list<string> $referencedColumns the columns they point at, in the same order
     * @param string       $onDelete          as SQL says it: "NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedSchema,
        public string $referencedTable,
        public array $referencedColumns,
        public string $onDelete,
    ) {
    }
}
