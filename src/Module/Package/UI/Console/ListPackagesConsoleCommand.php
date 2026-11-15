<?php

declare(strict_types=1);

namespace App\Module\Package\UI\Console;

use App\Module\Package\Application\Upgrade\Snapshot;
use App\Module\Package\Domain\Model\PackageInstallation;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:package:list', description: 'List the packages installed, with their versions')]
final class ListPackagesConsoleCommand
{
    public function __construct(
        private readonly PackageInstallationRepository $installations,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $installations = $this->installations->all();
        if ([] === $installations) {
            $io->text('No package is installed.');

            return Command::SUCCESS;
        }

        $io->table(['Name', 'Version', 'Requires', 'Installed', 'From'], array_map(static fn (PackageInstallation $installation): array => [
            $installation->getName(),
            $installation->getVersion(),
            self::requires($installation),
            $installation->getInstalledAt()->format('Y-m-d H:i'),
            $installation->getSource(),
        ], $installations));

        return Command::SUCCESS;
    }

    /**
     * The packages it requires, as "vendor/name ^1.0", comma separated.
     */
    private static function requires(PackageInstallation $installation): string
    {
        $requires = [];
        foreach (Snapshot::requires($installation->getSnapshot()) as $name => $constraint) {
            $requires[] = $name.' '.$constraint;
        }

        return implode(', ', $requires);
    }
}
