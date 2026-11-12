<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Application\Source\TemplateSources;
use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What metadata:template:repo (a tenant's indexes) and tenant:template:repo (the indexes offered
 * to every tenant) do: the same actions, in the session each runs in.
 */
final class TemplateRepoActions
{
    public function __construct(
        private readonly TemplateSources $sources,
    ) {
    }

    public function run(SymfonyStyle $io, string $command, string $action, ?string $name, ?string $url, ?string $key, bool $unsigned): int
    {
        try {
            match ($action) {
                'add' => $this->sources->add($name ?? throw new TemplateSourceFailed('add needs a name and an address.'), $url ?? throw new TemplateSourceFailed('add needs a name and an address.'), $key, $unsigned),
                'remove' => $this->sources->remove($name ?? throw new TemplateSourceFailed('remove needs the name of the index.')),
                'list' => null,
                default => throw new TemplateSourceFailed(\sprintf('"%s" is not an action: list, add or remove.', $action)),
            };
        } catch (TemplateSourceFailed $e) {
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
                $index['shared'] ? 'every tenant' : 'this tenant',
            ], $indexes));
        }

        return Command::SUCCESS;
    }
}
