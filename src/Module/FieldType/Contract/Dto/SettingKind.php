<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * The kind of value a field type setting holds; an editor maps each kind to its own control.
 */
enum SettingKind: string
{
    case Integer = 'integer';
    case Boolean = 'boolean';
    /** A string; empty means null. */
    case String = 'string';
    /** One of the "choices" option (value => label). */
    case Choice = 'choice';
    /** The name of an object. */
    case ObjectName = 'object_name';
    /** A list of {value, label} pairs. */
    case OptionList = 'option_list';
}
