<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Application\Upgrade\Strategy;
use App\Module\Template\Application\Upgrade\TemplateUpgrader;
use App\Module\Template\Domain\Exception\UpgradeRefused;
use App\TemplateFormat\InvalidPackage;
use App\TemplateFormat\PackageDirectory;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:upgrade', description: 'Upgrade an installed template to a new version: only what it changed, what this instance changed kept')]
final class UpgradeTemplateConsoleCommand
{
    public function __construct(
        private readonly PackageDirectory $directories,
        private readonly TemplateUpgrader $upgrader,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The directory of the new version')] string $directory,
        #[Option('For an item both changed: keep (this instance\'s version) or overwrite (the new one)')] ?string $strategy = null,
        #[Option('Go through the whole upgrade, then roll it back: nothing is changed')] bool $dryRun = false,
    ): int {
        $chosen = null === $strategy ? null : Strategy::tryFrom($strategy);
        if (null !== $strategy && null === $chosen) {
            $io->error('--strategy is keep or overwrite.');

            return Command::FAILURE;
        }
        try {
            $report = $this->upgrader->upgrade($this->directories->read($directory), realpath($directory) ?: $directory, $chosen, $dryRun);
        } catch (InvalidPackage $e) {
            $io->error(\sprintf('The template is invalid: %d problem(s).', \count($e->problems)));
            $io->listing(array_map(strval(...), $e->problems));

            return Command::FAILURE;
        } catch (UpgradeRefused $e) {
            $io->error($e->conflicts
                ? 'Both the new version and this instance changed these items: choose with --strategy=keep or --strategy=overwrite.'
                : 'The template cannot be upgraded.');
            $io->listing($e->reasons);

            return Command::FAILURE;
        }

        if ([] === $report->applied) {
            $io->text('Nothing to change: this instance already has everything the new version brings.');
        } else {
            $io->listing($report->applied);
        }
        if ([] !== $report->kept) {
            $io->section('Kept as they are here (--strategy=keep)');
            $io->listing($report->kept);
        }
        $io->success($report->dryRun
            ? \sprintf('%s can be upgraded from %s to %s. Dry run: nothing was changed.', $report->name, $report->from, $report->to)
            : \sprintf('%s upgraded from %s to %s.', $report->name, $report->from, $report->to));

        return Command::SUCCESS;
    }
}
