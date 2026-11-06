<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * What an upgrade does with an item both the new version and the instance changed.
 */
enum Strategy: string
{
    /** The instance's version stays. */
    case Keep = 'keep';
    /** The new version replaces it. */
    case Overwrite = 'overwrite';
}
