<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

/**
 * Dispatched after a change to the definitions has been committed.
 *
 * The fine-grained events (ObjectDefinitionCreated, FieldDefinitionCreated...) are
 * dispatched inside the transaction instead, so that their listeners can abort it.
 */
final readonly class MetadataChanged
{
    public function __construct(
        public string $objectName,
    ) {
    }
}
