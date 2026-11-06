<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Domain\Model\TemplateInstallation;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:list', description: 'List the templates installed, with their versions')]
final class ListTemplatesConsoleCommand
{
    public function __construct(
        private readonly TemplateInstallationRepository $installations,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $installations = $this->installations->all();
        if ([] === $installations) {
            $io->text('No template is installed.');

            return Command::SUCCESS;
        }

        $io->table(['Name', 'Version', 'Installed', 'From'], array_map(static fn (TemplateInstallation $installation): array => [
            $installation->getName(),
            $installation->getVersion(),
            $installation->getInstalledAt()->format('Y-m-d H:i'),
            $installation->getSource(),
        ], $installations));

        return Command::SUCCESS;
    }
}
