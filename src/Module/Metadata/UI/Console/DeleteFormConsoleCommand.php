<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\DeleteFormCommand;
use App\Module\Metadata\Application\Handler\DeleteFormHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:form:delete', description: 'Delete a composite form (its records stay)')]
final class DeleteFormConsoleCommand
{
    public function __construct(
        private readonly DeleteFormHandler $handler,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Form machine name')] string $name): int
    {
        try {
            ($this->handler)(new DeleteFormCommand($name));
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Form "%s" deleted; its records stay.', $name));

        return Command::SUCCESS;
    }
}
