<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

/**
 * Dispatched inside the transaction that persists a field set made unique together, or no
 * longer: a listener that throws (the unique index cannot be built) rolls it back.
 */
final readonly class ObjectUniqueTogetherConfigured
{
    public function __construct(
        public string $objectId,
        public string $objectName,
    ) {
    }
}
