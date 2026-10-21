<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * What happens to the records pointing at a record that is deleted.
 */
enum OnDelete: string
{
    /** The reference is cleared. */
    case SetNull = 'set_null';
    /** The deletion is refused while live records point at it. */
    case Restrict = 'restrict';
    /** The records pointing at it are deleted too. */
    case Cascade = 'cascade';
}
