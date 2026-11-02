<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

/**
 * Dispatched inside the transaction archiving or restoring a field. Its column stays either
 * way; what is built from it alone (the trigram index of the search) follows.
 */
final readonly class FieldArchiveChanged
{
    public function __construct(
        public string $objectName,
        public string $fieldName,
        public bool $isArchived,
    ) {
    }
}
