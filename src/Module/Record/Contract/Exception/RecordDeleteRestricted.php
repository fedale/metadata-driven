<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Exception;

/**
 * The record cannot be deleted: a "restrict" relation still points at it.
 */
final class RecordDeleteRestricted extends \DomainException
{
    public static function referencedBy(int $count, string $referencingLabel): self
    {
        return new self(\sprintf('This record cannot be deleted: %d %s still refer to it.', $count, $referencingLabel));
    }
}
