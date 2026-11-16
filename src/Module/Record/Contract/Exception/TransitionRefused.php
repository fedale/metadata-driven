<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Exception;

/**
 * A transition that may not be taken: no such one, not from the record's state, not the user's.
 */
final class TransitionRefused extends \DomainException
{
}
