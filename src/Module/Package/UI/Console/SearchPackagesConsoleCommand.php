<?php

declare(strict_types=1);

namespace App\Module\Package\UI\Console;

use App\Module\Package\Application\Source\LocatedPackage;
use App\Module\Package\Application\Source\PackageSources;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:package:search', description: 'Find packages in the indexes, with the version installed here')]
final class SearchPackagesConsoleCommand
{
    public function __construct(
        private readonly PackageSources $sources,
        private readonly PackageInstallationRepository $installations,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Part of a package\'s name; empty for every package')] string $term = '',
    ): int {
        try {
            $found = $this->sources->search($term);
        } catch (PackageSourceFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ([] === $found) {
            $io->text('' === $term ? 'The indexes offer no package.' : \sprintf('No package matches "%s".', $term));

            return Command::SUCCESS;
        }

        $io->table(['Package', 'Version', 'Index', 'Installed'], array_map(function (LocatedPackage $located): array {
            $installed = $this->installations->findByName($located->entry->name)?->getVersion();

            return [
                $located->entry->name,
                $located->entry->version,
                $located->source,
                match (true) {
                    null === $installed => '',
                    version_compare($installed, $located->entry->version, '<') => $installed.' (upgrade available)',
                    default => $installed,
                },
            ];
        }, $found));

        return Command::SUCCESS;
    }
}
