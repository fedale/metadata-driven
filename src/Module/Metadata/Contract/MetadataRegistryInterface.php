<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationReference;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;

/**
 * Entry point for every module that needs to know which objects and fields exist.
 */
interface MetadataRegistryInterface
{
    /**
     * @throws ObjectDefinitionNotFound
     */
    public function getObject(string $name): ObjectDefinitionDto;

    public function hasObject(string $name): bool;

    /**
     * @return array<string, ObjectDefinitionDto> keyed by object name
     */
    public function allObjects(): array;

    /**
     * The relation fields pointing at an object, from every object (itself included).
     *
     * @return list<RelationReference>
     */
    public function incomingRelations(string $targetName): array;
}
