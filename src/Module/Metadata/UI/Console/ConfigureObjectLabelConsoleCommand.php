<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\ConfigureObjectLabelCommand;
use App\Module\Metadata\Application\Handler\ConfigureObjectLabelHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:object:label', description: 'Choose the field that names the records of an object')]
final class ConfigureObjectLabelConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly ConfigureObjectLabelHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('Field name (default when omitted: the first text-like field)')] ?string $field = null,
    ): int {
        $command = new ConfigureObjectLabelCommand($object, $field);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            ($this->handler)($command);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Records of "%s" are named by %s.', $object, $field ?? 'the first text-like field'));

        return Command::SUCCESS;
    }
}
