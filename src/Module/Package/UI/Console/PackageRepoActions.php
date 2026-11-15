<?php

declare(strict_types=1);

namespace App\Module\Package\UI\Console;

use App\Module\Package\Application\Source\PackageSources;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What metadata:package:repo (a workspace's indexes) and system:package:repo (the indexes offered
 * to every workspace) do: the same actions, in the session each runs in.
 */
final class PackageRepoActions
{
    public function __construct(
        private readonly PackageSources $sources,
    ) {
    }

    public function run(SymfonyStyle $io, string $command, string $action, ?string $name, ?string $url, ?string $key, bool $unsigned): int
    {
        try {
            match ($action) {
                'add' => $this->sources->add($name ?? throw new PackageSourceFailed('add needs a name and an address.'), $url ?? throw new PackageSourceFailed('add needs a name and an address.'), $key, $unsigned),
                'remove' => $this->sources->remove($name ?? throw new PackageSourceFailed('remove needs the name of the index.')),
                'list' => null,
                default => throw new PackageSourceFailed(\sprintf('"%s" is not an action: list, add or remove.', $action)),
            };
        } catch (PackageSourceFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $indexes = $this->sources->indexes();
        if ([] === $indexes) {
            $io->text(\sprintf('No index: add one with %s add <name> <https address of its index.json>.', $command));
        } else {
            $io->table(['Name', 'Index', 'Signed', 'Offered to'], array_map(static fn (array $index): array => [
                $index['name'],
                $index['url'],
                null === $index['key'] ? 'no (accepted unsigned)' : 'yes',
                $index['shared'] ? 'every workspace' : 'this workspace',
            ], $indexes));
        }

        return Command::SUCCESS;
    }
}
