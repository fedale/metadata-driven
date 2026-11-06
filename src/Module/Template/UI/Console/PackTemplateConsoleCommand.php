<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\TemplateFormat\Archive;
use App\TemplateFormat\IndexEntry;
use App\TemplateFormat\InvalidIndex;
use App\TemplateFormat\InvalidPackage;
use App\TemplateFormat\PackageChecker;
use App\TemplateFormat\PackageDirectory;
use App\TemplateFormat\Problem;
use App\TemplateFormat\TemplateIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:pack', description: 'Pack a template into an archive, and list it in an index')]
final class PackTemplateConsoleCommand
{
    public function __construct(
        private readonly PackageDirectory $directories,
        private readonly PackageChecker $checker,
        private readonly Archive $archive,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The directory of the template')] string $directory,
        #[Argument('The archive to write, e.g. acme-shop-1.0.0.tar.gz')] string $archive,
        #[Option('An index.json to list it in, created if missing')] ?string $index = null,
        #[Option('The address the archive will be served at; default: its file name, next to the index')] ?string $url = null,
    ): int {
        try {
            $package = $this->directories->read($directory);
        } catch (InvalidPackage $e) {
            return self::invalid($io, $e->problems);
        }
        $problems = $this->checker->check($package);
        if ([] !== $problems) {
            return self::invalid($io, $problems);
        }

        $this->archive->pack($directory, $archive);
        $sha256 = (string) hash_file('sha256', $archive);
        $io->definitionList(['Template' => $package->name().' '.$package->version()], ['Archive' => $archive], ['sha256' => $sha256]);

        if (null !== $index) {
            try {
                $listed = is_file($index) ? TemplateIndex::fromJson((string) file_get_contents($index), '') : TemplateIndex::empty();
            } catch (InvalidIndex $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $requires = [];
            foreach (\is_array($package->manifest->spec['requires'] ?? null) ? $package->manifest->spec['requires'] : [] as $name => $constraint) {
                $requires[(string) $name] = \is_scalar($constraint) ? (string) $constraint : '';
            }
            $listed = $listed->with(new IndexEntry($package->name(), $package->version(), $url ?? basename($archive), $sha256, null, $requires));
            file_put_contents($index, $listed->toJson());
            $io->text(\sprintf('Listed in %s.', $index));
        }
        $io->success('Packed.');

        return Command::SUCCESS;
    }

    /**
     * @param list<Problem> $problems
     */
    private static function invalid(SymfonyStyle $io, array $problems): int
    {
        $io->error('The template is invalid: it is not packed.');
        $io->listing(array_map(strval(...), $problems));

        return Command::FAILURE;
    }
}
