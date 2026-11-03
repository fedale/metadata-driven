<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

/**
 * Where the links of a many-to-many field live, as SQL: the table, the column holding the
 * record and the one holding the linked record, and the table of the linked object. Every
 * identifier is quoted, the tables qualified.
 *
 * The store, the validation and the grid read links through this one shape only: a link table
 * of the field's own today, a junction object's table tomorrow.
 */
final readonly class LinkSource
{
    public function __construct(
        public string $table,
        public string $recordColumn,
        public string $targetColumn,
        public string $targetTable,
    ) {
    }
}
