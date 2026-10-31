<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * Where a view is used. Each object has at most one default view per kind.
 */
enum ViewKind: string
{
    /** Layout of the record create/edit form. */
    case Form = 'form';

    /** Columns of the record grid: a YAML definition, compiled when the view is saved. */
    case Grid = 'grid';
}
