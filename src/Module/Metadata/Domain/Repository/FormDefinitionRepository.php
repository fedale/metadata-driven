<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Repository;

use App\Module\Metadata\Domain\Model\FormDefinition;

interface FormDefinitionRepository
{
    public function save(FormDefinition $form): void;

    public function remove(FormDefinition $form): void;

    public function findByName(string $name): ?FormDefinition;

    /**
     * @return list<FormDefinition> ordered by name
     */
    public function findAll(): array;
}
