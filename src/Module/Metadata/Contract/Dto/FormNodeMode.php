<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * How many records of its object a node of a composite form holds.
 */
enum FormNodeMode: string
{
    /** One record (the root, or a one-to-one section): its fields are shown once. */
    case One = 'one';
    /** Repeatable rows, each one a record: added and removed in the form. */
    case Many = 'many';
}
