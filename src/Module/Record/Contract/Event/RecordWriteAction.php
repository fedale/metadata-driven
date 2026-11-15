<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Event;

/**
 * What a write does to a record, as its events tell.
 */
enum RecordWriteAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
