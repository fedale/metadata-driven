<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * Dispatched inside the transaction forgetting an archived object for good: the listeners drop
 * its table and its permissions.
 */
final readonly class ObjectDefinitionPurged
{
    /**
     * @param ObjectDefinitionDto $object the archived object, as it was
     */
    public function __construct(
        public ObjectDefinitionDto $object,
    ) {
    }
}
