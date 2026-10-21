<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

/**
 * Dispatched inside the transaction that persists the definition: a listener
 * that throws rolls the whole creation back.
 */
final readonly class FieldDefinitionCreated
{
    public function __construct(
        public string $objectId,
        public string $objectName,
        public string $fieldId,
        public string $fieldName,
    ) {
    }
}
