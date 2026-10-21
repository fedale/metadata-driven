<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

/**
 * Dispatched inside the transaction that persists the definition: a listener
 * that throws rolls the whole creation back.
 */
final readonly class ObjectDefinitionCreated
{
    public function __construct(
        public string $objectId,
        public string $objectName,
    ) {
    }
}
