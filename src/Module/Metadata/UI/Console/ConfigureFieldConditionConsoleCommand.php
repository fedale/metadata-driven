<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\ConfigureFieldConditionCommand;
use App\Module\Metadata\Application\Handler\ConfigureFieldConditionHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:field:condition', description: 'Make a field apply only while another field holds some values')]
final class ConfigureFieldConditionConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly ConfigureFieldConditionHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('Field machine name')] string $field,
        #[Option('The deciding field, a choice or a yes/no (omit it to remove the condition)')] ?string $when = null,
        #[Option('Comma separated values of the deciding field, e.g. "course,certification" or "true"')] string $in = '',
    ): int {
        $values = array_values(array_filter(array_map(trim(...), explode(',', $in)), static fn (string $value): bool => '' !== $value));
        $command = new ConfigureFieldConditionCommand($object, $field, $when, $values);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            ($this->handler)($command);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(null === $when
            ? \sprintf('Field "%s.%s" always applies.', $object, $field)
            : \sprintf('Field "%s.%s" applies when %s is %s.', $object, $field, $when, implode(' or ', $values)));

        return Command::SUCCESS;
    }
}
