<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationReference;
use App\Module\Metadata\Contract\Exception\FormDefinitionNotFound;
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
     * The archived objects: out of the application, their tables and records kept until they
     * are purged. Read for the admin and the schema only; never cached.
     *
     * @return array<string, ObjectDefinitionDto> keyed by object name
     */
    public function archivedObjects(): array;

    /**
     * The relation fields pointing at an object, from every (active) object, itself included.
     *
     * @return list<RelationReference>
     */
    public function incomingRelations(string $targetName): array;

    /**
     * @throws FormDefinitionNotFound
     */
    public function getForm(string $name): FormDefinitionDto;

    public function hasForm(string $name): bool;

    /**
     * @return array<string, FormDefinitionDto> keyed by form name
     */
    public function allForms(): array;
}
