<?php

declare(strict_types=1);

namespace App\Module\Package\Contract;

use App\Module\Package\Contract\Exception\PackageInstallFailed;

/**
 * Installs a package of the indexes in the current workspace, as metadata:package:install does:
 * with the packages it requires that are missing, all or nothing.
 */
interface PackageInstallationInterface
{
    /**
     * @param string      $package vendor/name, or vendor/name@1.0.0
     * @param string|null $repo    the index to take it from, by name; else the first that has it
     *
     * @return list<string> the packages installed, "vendor/name 1.0.0", the one asked for last
     *
     * @throws PackageInstallFailed
     */
    public function install(string $package, ?string $repo = null): array;
}
