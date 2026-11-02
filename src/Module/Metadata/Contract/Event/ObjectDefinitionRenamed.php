<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * Dispatched inside the transaction renaming an object, once the definitions are saved: the
 * listeners rename what is named after it (the table, the permissions).
 */
final readonly class ObjectDefinitionRenamed
{
    /**
     * @param ObjectDefinitionDto $before the object under its old name
     */
    public function __construct(
        public ObjectDefinitionDto $before,
        public string $newName,
    ) {
    }
}
