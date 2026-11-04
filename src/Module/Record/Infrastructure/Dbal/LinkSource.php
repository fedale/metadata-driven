<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

/**
 * Where the links of a many-to-many field live, as SQL: the table, the column holding the
 * record and the one holding the linked record, the table of the linked object, and the soft
 * delete column of the links, if they have one. Every identifier is quoted, the tables
 * qualified.
 *
 * The store, the validation and the grid read links through this one shape only: a link table
 * of the field's own (no soft delete: a link is there or not), or the table of a junction object
 * (a link is one of its live records).
 */
final readonly class LinkSource
{
    public function __construct(
        public string $table,
        public string $recordColumn,
        public string $targetColumn,
        public string $targetTable,
        public ?string $deletedColumn = null,
        // Whether the links are the records of a junction object, written by its own pipeline.
        public bool $isJunction = false,
    ) {
    }

    /**
     * The condition keeping the live links only, " AND ..." to append to a WHERE or a JOIN ON;
     * empty for a link table.
     *
     * @param string|null $alias the quoted alias of the links table in the query, if any
     */
    public function andLive(?string $alias = null): string
    {
        return null === $this->deletedColumn ? '' : \sprintf(' AND %s%s IS NULL', null === $alias ? '' : $alias.'.', $this->deletedColumn);
    }
}
