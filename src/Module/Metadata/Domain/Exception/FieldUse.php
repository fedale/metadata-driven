<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Exception;

/**
 * What a field is refused for, when its kind of value cannot serve it: each refusal of
 * InvalidMetadataDefinition is a whole sentence per use, so that it translates.
 */
enum FieldUse
{
    /** Made unique, alone or together with other fields. */
    case Unique;

    /** Searched by the record search. */
    case Searched;

    /** The label of the records, naming them in relations and searches. */
    case Label;
}
