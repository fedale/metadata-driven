<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Repository;

use App\Module\Template\Domain\Model\TemplateInstallation;

interface TemplateInstallationRepository
{
    public function findByName(string $name): ?TemplateInstallation;

    /**
     * @return list<TemplateInstallation> by name
     */
    public function all(): array;

    public function save(TemplateInstallation $installation): void;
}
