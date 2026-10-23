<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\SaveFormCommand;
use App\Module\Metadata\Application\Handler\SaveFormHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:form:save', description: 'Create or replace a composite form from its YAML definition')]
final class SaveFormConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly SaveFormHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Form machine name')] string $name,
        #[Argument('File holding the YAML definition, "-" for the standard input')] string $file,
    ): int {
        $definition = @file_get_contents('-' === $file ? 'php://stdin' : $file);
        if (false === $definition) {
            $io->error(\sprintf('Cannot read "%s".', $file));

            return Command::FAILURE;
        }

        $command = new SaveFormCommand($name, $definition);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            ($this->handler)($command);
        } catch (MetadataInputInvalid $e) {
            $io->error('The definition is invalid.');
            $io->listing(array_merge(...array_values($e->errors)));

            return Command::FAILURE;
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Form "%s" saved: /f/%s/new', $name, $name));

        return Command::SUCCESS;
    }
}
