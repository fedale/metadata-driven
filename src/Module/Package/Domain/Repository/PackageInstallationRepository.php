<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Repository;

use App\Module\Package\Domain\Model\PackageInstallation;

interface PackageInstallationRepository
{
    public function findByName(string $name): ?PackageInstallation;

    /**
     * @return list<PackageInstallation> by name
     */
    public function all(): array;

    public function save(PackageInstallation $installation): void;
}
