<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\DeleteViewCommand;
use App\Module\Metadata\Application\Handler\DeleteViewHandler;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:view:delete', description: 'Delete a view')]
final class DeleteViewConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly DeleteViewHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('View machine name')] string $name,
        #[Option('Where the view is used: "form"')] string $kind = ViewKind::Form->value,
    ): int {
        $viewKind = ViewKind::tryFrom($kind);
        if (null === $viewKind) {
            $io->error(\sprintf('Unknown view kind "%s". Available kinds: %s.', $kind, implode(', ', array_column(ViewKind::cases(), 'value'))));

            return Command::INVALID;
        }

        $command = new DeleteViewCommand($object, $name, $viewKind);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            ($this->handler)($command);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('View "%s" (%s) of "%s" deleted.', $name, $viewKind->value, $object));

        return Command::SUCCESS;
    }
}
