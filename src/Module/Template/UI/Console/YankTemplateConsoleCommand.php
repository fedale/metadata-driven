<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use Fedale\TemplateFormat\IndexEntry;
use Fedale\TemplateFormat\InvalidIndex;
use Fedale\TemplateFormat\Signature;
use Fedale\TemplateFormat\TemplateIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:yank', description: 'Withdraw a version from an index, or list it again')]
final class YankTemplateConsoleCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The index.json to change')] string $index,
        #[Argument('The template, vendor/name')] string $name,
        #[Argument('The version')] string $version,
        #[Option('A file holding the secret key to sign the entry again with: the yanked flag is signed')] ?string $sign = null,
        #[Option('List the version again instead')] bool $undo = false,
    ): int {
        try {
            $listed = TemplateIndex::fromJson(is_file($index) ? (string) file_get_contents($index) : '', '');
        } catch (InvalidIndex $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $entry = $listed->find($name, $version);
        if (null === $entry) {
            $io->error(\sprintf('%s lists no version %s of %s.', $index, $version, $name));

            return Command::FAILURE;
        }

        $changed = new IndexEntry($entry->name, $entry->version, $entry->url, $entry->sha256, null, $entry->requires, !$undo);
        if (null !== $entry->signature && null === $sign) {
            $io->error('The entry is signed, and the signature covers the yanked flag: give the secret key (--sign).');

            return Command::FAILURE;
        }
        if (null !== $sign) {
            try {
                $changed = new IndexEntry($changed->name, $changed->version, $changed->url, $changed->sha256, Signature::sign($changed, is_file($sign) ? (string) file_get_contents($sign) : ''), $changed->requires, $changed->yanked);
            } catch (\InvalidArgumentException $e) {
                $io->error(\sprintf('%s: %s', $sign, $e->getMessage()));

                return Command::FAILURE;
            }
        }
        file_put_contents($index, $listed->with($changed)->toJson());
        $io->success(\sprintf('%s %s %s.', $name, $version, $undo ? 'is listed again' : 'is yanked: new installs and upgrades skip it'));

        return Command::SUCCESS;
    }
}
