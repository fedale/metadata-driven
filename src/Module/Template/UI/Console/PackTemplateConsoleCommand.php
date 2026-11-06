<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use Fedale\TemplateFormat\Archive;
use Fedale\TemplateFormat\IndexEntry;
use Fedale\TemplateFormat\InvalidIndex;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\Problem;
use Fedale\TemplateFormat\Signature;
use Fedale\TemplateFormat\TemplateIndex;
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
        #[Option('A file holding the Ed25519 secret key to sign the entry with (metadata:template:keygen)')] ?string $sign = null,
    ): int {
        if (null !== $sign && null === $index) {
            $io->error('--sign signs the entry of an index: give the index too (--index).');

            return Command::FAILURE;
        }
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
            $entry = new IndexEntry($package->name(), $package->version(), $url ?? basename($archive), $sha256, null, $requires);
            if (null !== $sign) {
                try {
                    $signature = Signature::sign($entry, is_file($sign) ? (string) file_get_contents($sign) : '');
                } catch (\InvalidArgumentException $e) {
                    $io->error(\sprintf('%s: %s', $sign, $e->getMessage()));

                    return Command::FAILURE;
                }
                $entry = new IndexEntry($entry->name, $entry->version, $entry->url, $entry->sha256, $signature, $entry->requires);
            }
            $listed = $listed->with($entry);
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
