<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Application\Source\LocatedTemplate;
use App\Module\Template\Application\Source\TemplateSources;
use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:search', description: 'Find templates in the indexes, with the version installed here')]
final class SearchTemplatesConsoleCommand
{
    public function __construct(
        private readonly TemplateSources $sources,
        private readonly TemplateInstallationRepository $installations,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Part of a template\'s name; empty for every template')] string $term = '',
    ): int {
        try {
            $found = $this->sources->search($term);
        } catch (TemplateSourceFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ([] === $found) {
            $io->text('' === $term ? 'The indexes offer no template.' : \sprintf('No template matches "%s".', $term));

            return Command::SUCCESS;
        }

        $io->table(['Template', 'Version', 'Index', 'Installed'], array_map(function (LocatedTemplate $located): array {
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
