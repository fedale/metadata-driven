<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * When a composite form asks a service field: every time its record is saved, or only when the
 * record is created ("I accept the terms" once, at sign-up).
 */
enum ServiceFieldAsk: string
{
    /** On the new record and on every edit: it must be given again each time. */
    case Always = 'always';
    /** On the new record only: an existing one neither shows nor checks it. */
    case Create = 'create';
}
