<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\ConfigureUniqueTogetherCommand;
use App\Module\Metadata\Application\Handler\ConfigureUniqueTogetherHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:object:unique', description: 'Make a set of fields unique together, or no longer')]
final class ConfigureUniqueTogetherConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly ConfigureUniqueTogetherHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('Comma separated fields, at least two, e.g. "user,tag"')] string $fields,
        #[Option('Remove the set instead of adding it')] bool $remove = false,
    ): int {
        $fieldList = array_values(array_filter(array_map(trim(...), explode(',', $fields)), static fn (string $field): bool => '' !== $field));

        $command = new ConfigureUniqueTogetherCommand($object, $fieldList, $remove);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            ($this->handler)($command);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            $remove ? 'Fields %s of "%s" are no longer unique together; their index is dropped.' : 'Fields %s of "%s" are unique together; their unique index is built.',
            implode(', ', $fieldList),
            $object,
        ));

        return Command::SUCCESS;
    }
}
