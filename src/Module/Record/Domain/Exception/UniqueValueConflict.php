<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Exception;

/**
 * The database rejected a write because of a unique index (e.g. a concurrent write won the race).
 */
final class UniqueValueConflict extends \RuntimeException
{
}
