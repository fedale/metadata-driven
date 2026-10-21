<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Repository;

use App\Module\Metadata\Domain\Model\ObjectDefinition;

interface ObjectDefinitionRepository
{
    public function save(ObjectDefinition $object): void;

    public function findByName(string $name): ?ObjectDefinition;

    /**
     * @return list<ObjectDefinition> ordered by name, with fields loaded
     */
    public function findAll(): array;
}
