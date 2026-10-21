<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * Physical column types a field type can ask for; values match Doctrine DBAL type names.
 */
enum ColumnType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date_immutable';
    case DateTime = 'datetime_immutable';
    case Guid = 'guid';
}
