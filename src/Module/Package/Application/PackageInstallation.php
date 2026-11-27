<?php

declare(strict_types=1);

namespace App\Module\Package\Application;

use App\Module\Package\Application\Source\PackageLocator;
use App\Module\Package\Contract\Exception\PackageInstallFailed;
use App\Module\Package\Contract\PackageInstallationInterface;
use App\Module\Package\Domain\Exception\InstallRefused;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;
use Fedale\PackageFormat\InvalidPackage;

/**
 * metadata:package:install for the other modules (the system admin's workflows).
 */
final class PackageInstallation implements PackageInstallationInterface
{
    public function __construct(
        private readonly PackageLocator $locator,
        private readonly PackageInstaller $installer,
    ) {
    }

    public function install(string $package, ?string $repo = null): array
    {
        try {
            $chain = $this->locator->openWithRequirements($package, $repo);
            try {
                $reports = $this->installer->installAll($chain);
            } finally {
                foreach ($chain as $opened) {
                    $this->locator->close($opened);
                }
            }
        } catch (PackageSourceFailed $e) {
            throw new PackageInstallFailed($e->text(), 0, $e);
        } catch (InvalidPackage $e) {
            throw new PackageInstallFailed(new Message('package.install.invalid', 'The package is invalid: %problems%', ['problems' => ProblemMessages::list($e, ' ')]), 0, $e);
        } catch (InstallRefused $e) {
            throw new PackageInstallFailed(new Message('package.install.refused', 'The package cannot be installed: %reason%', ['reason' => new MessageList($e->reasons, ' ')]), 0, $e);
        }

        return array_map(static fn (InstallReport $report): string => $report->name.' '.$report->version, $reports);
    }
}
