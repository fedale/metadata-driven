<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * Dispatched inside the transaction forgetting an archived field for good: the schema drops
 * its column.
 */
final readonly class FieldDefinitionPurged
{
    /**
     * @param ObjectDefinitionDto $object the object of the field, before the purge
     * @param FieldDefinitionDto  $field  the archived field
     */
    public function __construct(
        public ObjectDefinitionDto $object,
        public FieldDefinitionDto $field,
    ) {
    }
}
