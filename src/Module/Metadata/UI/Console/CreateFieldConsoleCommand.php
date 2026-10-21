<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:field:create', description: 'Add a field to an object definition')]
final class CreateFieldConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly CreateFieldHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('Field machine name')] string $name,
        #[Argument('Field type, e.g. "text"')] string $type,
        #[Argument('Field label')] string $label,
        #[Option('The field is mandatory')] bool $required = false,
        #[Option('Values must be unique')] bool $unique = false,
        #[Option('Type settings as JSON, e.g. {"maxLength":100}')] string $settings = '{}',
    ): int {
        try {
            $decoded = json_decode($settings, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(\sprintf('Invalid JSON in --settings: %s', $e->getMessage()));

            return Command::INVALID;
        }
        if (!\is_array($decoded) || ([] !== $decoded && array_is_list($decoded))) {
            $io->error('--settings must be a JSON object.');

            return Command::INVALID;
        }
        /** @var array<string, mixed> $decoded */
        $command = new CreateFieldCommand($object, $name, $type, $label, $required, $unique, $decoded);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            $id = ($this->handler)($command);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Field "%s.%s" created (%s).', $object, $name, $id));

        return Command::SUCCESS;
    }
}
