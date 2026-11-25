<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * Input widgets a field type can ask for. The values are the names of the controls of
 * fedale/gridview-bundle (its ControlType), which draws every record form: a test checks that
 * each one exists there. The enum stays here, so that the domain does not depend on the bundle.
 */
enum WidgetKind: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Integer = 'integer';
    /** A number with decimals; option "scale" is their count. */
    case Number = 'number';
    /** A yes/no; option "display" is "checkbox" or "switch". */
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    /** A time of day; option "seconds". */
    case Time = 'time';
    /** A colour as #rrggbb. */
    case Color = 'color';
    /** A country, a language, a currency: an ISO code; option "preferred" (codes listed first). */
    case Country = 'country';
    case Language = 'language';
    case Currency = 'currency';
    case Email = 'email';
    /** A secret typed twice when option "confirm" is true; never prefilled. */
    case Password = 'password';
    case Url = 'url';
    case Tel = 'tel';
    case Choice = 'choice';
    /**
     * A record of another object, or any number of them with option "multiple"; option
     * "target" is its object name.
     */
    case Relation = 'relation';
    /** An uploaded file, or several with option "multiple"; options "accept" (MIME types), "maxSize" (in MB) and "image". */
    case Media = 'media';
    /**
     * Free text labels, a list of strings; options "suggestions" (labels offered), "restrict"
     * (only those), "max" (the most labels) and "maxLength" (the longest one).
     */
    case Tags = 'tags';
}
