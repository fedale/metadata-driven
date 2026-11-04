<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

/**
 * What a user gets of a many-to-many field going through a junction object, per their
 * permissions on the junction (see RecordFormFactory::addRows()).
 */
enum JunctionAccess
{
    /** Read, create, update and delete, and delete on what that cascades to: the rows, editable. */
    case Rows;
    /** Read without the rest: the rows, shown. */
    case ReadOnly;
    /** No read: the labels of the linked records only, as the grid shows them. */
    case Labels;
}
