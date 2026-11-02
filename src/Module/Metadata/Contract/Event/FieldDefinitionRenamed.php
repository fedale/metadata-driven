<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Event;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * Dispatched inside the transaction renaming a field, once the definitions are saved: the
 * schema renames its column, and what is named after it.
 */
final readonly class FieldDefinitionRenamed
{
    /**
     * @param ObjectDefinitionDto $before the object of the field, before the rename
     */
    public function __construct(
        public ObjectDefinitionDto $before,
        public string $fieldName,
        public string $newName,
    ) {
    }
}
