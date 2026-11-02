<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Repository;

use App\Module\Metadata\Domain\Model\ObjectDefinition;

interface ObjectDefinitionRepository
{
    public function save(ObjectDefinition $object): void;

    /**
     * Any object, the archived ones included: their names stay taken.
     */
    public function findByName(string $name): ?ObjectDefinition;

    /**
     * Forgets an object, its fields and its views.
     */
    public function remove(ObjectDefinition $object): void;

    /**
     * @return list<ObjectDefinition> ordered by name, with fields loaded; the archived ones included
     */
    public function findAll(): array;
}
