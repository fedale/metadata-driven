<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * The compiled definition of a grid view: which columns the record grid shows, in order,
 * its initial sort and its page size.
 */
final readonly class GridViewDto
{
    /**
     * @param list<GridColumnDto>         $columns
     * @param array<string, 'asc'|'desc'> $sort     column key (or created_at, updated_at) => direction
     * @param int|null                    $pageSize null: the grid's default
     */
    public function __construct(
        public array $columns,
        public array $sort,
        public ?int $pageSize,
    ) {
    }

    public function column(string $key): ?GridColumnDto
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }
}
