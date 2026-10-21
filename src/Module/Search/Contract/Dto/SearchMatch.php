<?php

declare(strict_types=1);

namespace App\Module\Search\Contract\Dto;

/**
 * How a search value must occur in a field. Comparisons are always case-insensitive.
 */
enum SearchMatch: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
}
