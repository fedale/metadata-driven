<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * Input widgets a field type can ask for; each UI maps them to its own controls.
 */
enum WidgetKind: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case DateTime = 'datetime';
    case Email = 'email';
    /** A secret typed twice when option "confirm" is true; never prefilled. */
    case Password = 'password';
    case Url = 'url';
    case Tel = 'tel';
    case Choice = 'choice';
    /** A record of another object; option "target" is its object name. */
    case Relation = 'relation';
    /** Any number of records of another object; option "target" is its object name. */
    case ManyToMany = 'many_to_many';
}
