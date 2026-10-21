<?php

declare(strict_types=1);

namespace App\Module\Validation\Contract;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * Port implemented by the module owning record storage: does a live record exist?
 *
 * Gives a readable error early; the foreign key stays the authority against
 * concurrent writes.
 */
interface ReferenceCheckerInterface
{
    public function exists(ObjectDefinitionDto $target, string $id): bool;
}
