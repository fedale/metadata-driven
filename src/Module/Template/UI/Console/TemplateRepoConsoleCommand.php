<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The indexes of the command's tenant, listed with those offered to every tenant
 * (tenant:template:repo), which it may not remove.
 */
#[AsCommand(name: 'metadata:template:repo', description: 'List, add or remove the indexes templates are downloaded from')]
final class TemplateRepoConsoleCommand
{
    public function __construct(
        private readonly TemplateRepoActions $actions,
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
        return $this->actions->run($io, 'metadata:template:repo', $action, $name, $url, $key, $unsigned);
    }
}
