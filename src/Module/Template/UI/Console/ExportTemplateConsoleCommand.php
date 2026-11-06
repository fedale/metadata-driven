<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Template\Application\Command\ExportTemplateCommand;
use App\Module\Template\Application\TemplateExporter;
use App\Module\Template\Contract\Exception\TemplateException;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\PackageDirectory;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:export', description: 'Write objects, their fields and views, composite forms and their translations as a template')]
final class ExportTemplateConsoleCommand
{
    public function __construct(
        private readonly TemplateExporter $exporter,
        private readonly PackageDirectory $directories,
    ) {
    }

    /**
     * @param list<string> $object
     * @param list<string> $form
     * @param list<string> $seed
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The template\'s name, vendor/name (e.g. acme/shop)')] string $name,
        #[Argument('Its version, major.minor.patch (e.g. 1.0.0)')] string $release,
        #[Argument('A new or empty directory to write it into')] string $directory,
        #[Option('An object to export, with its fields and views (repeatable)')] array $object = [],
        #[Option('A composite form to export; its objects must be exported too (repeatable)')] array $form = [],
        #[Option('The title of the template; default: from its name')] ?string $label = null,
        #[Option('A line about what it is for')] ?string $description = null,
        #[Option('Its license, an SPDX identifier (e.g. MIT)')] string $license = 'proprietary',
        #[Option('Export the records of an object as a seed: object, or object:key to name its key field (repeatable)')] array $seed = [],
    ): int {
        $seeds = [];
        foreach ($seed as $option) {
            [$objectName, $key] = array_pad(explode(':', $option, 2), 2, null);
            $seeds[$objectName] = '' === $key ? null : $key;
        }
        try {
            $package = $this->exporter->export(new ExportTemplateCommand($name, $release, $object, $form, $label, $description, $license, $seeds));
            $this->directories->write($package, $directory);
        } catch (TemplateException|MetadataException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Template %s %s written to %s.', $package->name(), $package->version(), $directory));
        $io->listing(array_map(static fn (Document $document): string => $document->path, [$package->manifest, ...$package->documents]));

        return Command::SUCCESS;
    }
}
