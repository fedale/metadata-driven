<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\ConfigureFieldSettingsCommand;
use App\Module\Metadata\Application\Handler\ConfigureFieldSettingsHandler;
use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:field:settings', description: 'Change some settings of an existing field')]
final class ConfigureFieldSettingsConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly ConfigureFieldSettingsHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Argument('Field machine name')] string $field,
        #[Argument('The settings to change as JSON, e.g. {"strengthMeter":true}; the others keep their value')] string $settings,
    ): int {
        try {
            $decoded = json_decode($settings, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(\sprintf('Invalid JSON: %s', $e->getMessage()));

            return Command::INVALID;
        }
        if (!\is_array($decoded) || ([] !== $decoded && array_is_list($decoded))) {
            $io->error('The settings must be a JSON object.');

            return Command::INVALID;
        }
        /** @var array<string, mixed> $decoded */
        $command = new ConfigureFieldSettingsCommand($object, $field, $decoded);
        if (!$this->isValid($this->validator, $command, $io)) {
            return Command::INVALID;
        }

        try {
            ($this->handler)($command);
        } catch (InvalidFieldSettings $e) {
            $io->error('The settings are invalid.');
            $io->listing($e->errors);

            return Command::FAILURE;
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Settings of "%s.%s" saved.', $object, $field));

        return Command::SUCCESS;
    }
}
