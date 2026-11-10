<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Application\InstallReport;
use App\Module\Template\Application\Source\PackageLocator;
use App\Module\Template\Application\TemplateInstaller;
use App\Module\Template\Domain\Exception\InstallRefused;
use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use Fedale\TemplateFormat\InvalidPackage;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:install', description: 'Install a template: its objects and their tables, fields, views, forms and translations, all or nothing')]
final class InstallTemplateConsoleCommand
{
    public function __construct(
        private readonly PackageLocator $locator,
        private readonly TemplateInstaller $installer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The directory of the template, or its name in an index: vendor/name, vendor/name@1.0.0')] string $template,
        #[Option('Go through the whole install, then roll it back: nothing is changed')] bool $dryRun = false,
        #[Option('The index to download it from, by name (metadata:template:repo list)')] ?string $repo = null,
    ): int {
        try {
            // Named from an index, it comes with the templates it requires that are missing.
            $chain = $this->locator->openWithRequirements($template, $repo);
            try {
                $reports = $this->installer->installAll($chain, $dryRun);
            } finally {
                foreach ($chain as $opened) {
                    $this->locator->close($opened);
                }
            }
        } catch (TemplateSourceFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (InvalidPackage $e) {
            $io->error(\sprintf('The template is invalid: %d problem(s).', \count($e->problems)));
            $io->listing(array_map(strval(...), $e->problems));

            return Command::FAILURE;
        } catch (InstallRefused $e) {
            $io->error(1 === \count($e->reasons) ? 'The template cannot be installed.' : \sprintf('The template cannot be installed: %d reasons.', \count($e->reasons)));
            $io->listing($e->reasons);

            return Command::FAILURE;
        }

        $last = $reports[\count($reports) - 1];
        if (\count($reports) > 1) {
            $io->note(\sprintf('%s requires templates this instance does not have: they are installed first, %s.', $last->name, implode(', ', array_map(static fn (InstallReport $report): string => \sprintf('%s %s from %s', $report->name, $report->version, $report->source), \array_slice($reports, 0, -1)))));
        }
        foreach ($reports as $report) {
            if (\count($reports) > 1) {
                $io->section($report->name.' '.$report->version);
            }
            $io->definitionList(
                ['Objects' => implode(', ', $report->objects)],
                ['Fields' => (string) $report->fields],
                ['Views' => (string) $report->views],
                ['Forms' => [] === $report->forms ? '—' : implode(', ', $report->forms)],
                ['Translations' => (string) $report->translations],
                ['Records' => (string) $report->records],
            );
            if ([] !== $report->skippedLocales) {
                $io->warning(\sprintf('Languages this instance does not have, left out: %s.', implode(', ', $report->skippedLocales)));
            }
        }
        $installed = implode(', ', array_map(static fn (InstallReport $report): string => $report->name.' '.$report->version, $reports));
        $noun = \count($reports) > 1 ? 'Templates' : 'Template';
        if ($last->dryRun) {
            $io->success(\sprintf('%s %s can be installed. Dry run: nothing was changed.', $noun, $installed));
        } else {
            $io->success(\sprintf('%s %s installed.', $noun, $installed));
            $io->note('Give the users the permissions of the new objects (record.<object>.read, ...): see access.md.');
        }

        return Command::SUCCESS;
    }
}
