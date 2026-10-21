<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * What a user may do with the records of an object; one permission per object and action.
 */
enum RecordAction: string
{
    /** List, open, search, export, and pick them in a relation. */
    case Read = 'read';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
