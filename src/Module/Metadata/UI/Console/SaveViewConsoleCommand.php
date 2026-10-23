<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\SaveViewCommand;
use App\Module\Metadata\Application\Handler\SaveViewHandler;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:view:save', description: 'Create or replace a view: an HTML layout with a {field} token per field')]
final class SaveViewConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly SaveViewHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('View machine name')] string $name,
        #[Argument('File holding the HTML layout, "-" for the standard input')] string $file,
        #[Option('Where the view is used: "form"')] string $kind = ViewKind::Form->value,
        #[Option('Make it the default view of its kind')] bool $default = false,
    ): int {
        $viewKind = ViewKind::tryFrom($kind);
        if (null === $viewKind) {
            $io->error(\sprintf('Unknown view kind "%s". Available kinds: %s.', $kind, implode(', ', array_column(ViewKind::cases(), 'value'))));

            return Command::INVALID;
        }
        $template = @file_get_contents('-' === $file ? 'php://stdin' : $file);
        if (false === $template) {
            $io->error(\sprintf('Cannot read "%s".', $file));

            return Command::FAILURE;
        }

        $command = new SaveViewCommand($object, $name, $template, $viewKind, $default);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            ($this->handler)($command);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('View "%s" (%s) of "%s" saved.', $name, $viewKind->value, $object));

        return Command::SUCCESS;
    }
}
