<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * What happens to the links of a many-to-many field when a linked record is deleted.
 *
 * There is no cascade: deleting a tag must never delete the contacts it was linked to.
 */
enum ManyToManyOnDelete: string
{
    /** The links to the deleted record are removed; the records holding them stay. */
    case Unlink = 'unlink';
    /** The deletion is refused while live records are linked to it. */
    case Restrict = 'restrict';
}
