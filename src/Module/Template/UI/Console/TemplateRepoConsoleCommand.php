<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Application\Source\TemplateSources;
use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:repo', description: 'List, add or remove the indexes templates are downloaded from')]
final class TemplateRepoConsoleCommand
{
    public function __construct(
        private readonly TemplateSources $sources,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('list, add or remove')] string $action = 'list',
        #[Argument('The name of the index (add, remove)')] ?string $name = null,
        #[Argument('The https address of its index.json (add)')] ?string $url = null,
        #[Option('add: the Ed25519 public key (base64) its entries are signed with')] ?string $key = null,
        #[Option('add: accept an index without signatures, trusting its checksums only')] bool $unsigned = false,
    ): int {
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
            $io->text('No index: add one with metadata:template:repo add <name> <https address of its index.json>.');
        } else {
            $io->table(['Name', 'Index', 'Signed'], array_map(static fn (array $index): array => [$index['name'], $index['url'], null === $index['key'] ? 'no (accepted unsigned)' : 'yes'], $indexes));
        }

        return Command::SUCCESS;
    }
}
