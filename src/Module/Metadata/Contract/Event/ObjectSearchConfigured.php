<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

/**
 * Dispatched inside the transaction that persists the new search configuration: a
 * listener that throws rolls it back.
 */
final readonly class ObjectSearchConfigured
{
    public function __construct(
        public string $objectId,
        public string $objectName,
    ) {
    }
}
