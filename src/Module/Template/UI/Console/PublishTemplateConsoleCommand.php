<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Application\Publish\TemplatePublisher;
use App\Module\Template\Domain\Exception\PublishRefused;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:publish', description: 'Prepare a template version for the central catalog: copied into a checkout of it, ready for a pull request')]
final class PublishTemplateConsoleCommand
{
    public function __construct(private readonly TemplatePublisher $publisher)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The directory of the template (metadata:template:export writes one)')] string $directory,
        #[Option('A checkout of the catalog, fedale/metadata-driven-templates, up to date')] string $catalog = '../metadata-driven-templates',
        #[Option('Check everything the catalog would refuse, copy nothing')] bool $dryRun = false,
    ): int {
        try {
            $report = $this->publisher->publish($directory, $catalog, $dryRun);
        } catch (PublishRefused $e) {
            $io->error(\sprintf('The catalog would refuse it: %d problem(s).', \count($e->reasons)));
            $io->listing($e->reasons);

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Template' => $report->name.' '.$report->version],
            ['In the catalog' => $report->directory],
            ['Files' => \sprintf('%d, %.1f kB', $report->files, $report->bytes / 1000)],
            ['Compared with' => $report->previous ?? 'none: its first version'],
        );
        if ($report->dryRun) {
            $io->success('The catalog would take it. Dry run: nothing was copied.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Copied into %s/%s.', rtrim($catalog, '/'), $report->directory));
        $io->text('Then, in the catalog, propose it in a pull request:');
        $io->listing([
            \sprintf('cd %s', $catalog),
            \sprintf('git checkout -b %s main', $report->branch()),
            \sprintf('git add %s', $report->directory),
            \sprintf('git commit -m "Add %s %s"', $report->name, $report->version),
            \sprintf('git push -u origin %s', $report->branch()),
            'open the pull request against main: its check runs, then a maintainer reviews it',
        ]);
        $io->note('The pull request must come from an owner of the vendor (vendors.yaml), or from a public member of an organization owning it.');

        return Command::SUCCESS;
    }
}
