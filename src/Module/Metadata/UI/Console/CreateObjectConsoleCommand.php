<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:object:create', description: 'Create a new object definition')]
final class CreateObjectConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly CreateObjectHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Machine name, e.g. "pet"')] string $name,
        #[Argument('Singular label, e.g. "Pet"')] string $labelSingular,
        #[Argument('Plural label, e.g. "Pets"')] string $labelPlural,
        #[Option('Icon name')] ?string $icon = null,
        #[Option('The type of the key, fixed once the object exists: "uuid" (made by the app) or "integer" (numbered by the database)')] string $key = 'uuid',
    ): int {
        $keyType = KeyType::tryFrom($key);
        if (null === $keyType) {
            $io->error(\sprintf('"%s" is no key type: "uuid" or "integer".', $key));

            return Command::INVALID;
        }
        $command = new CreateObjectCommand($name, $labelSingular, $labelPlural, $icon, $keyType);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            $id = ($this->handler)($command);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Object "%s" created (%s).', $name, $id));

        return Command::SUCCESS;
    }
}
