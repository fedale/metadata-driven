<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Application\Command\ConfigureObjectSearchCommand;
use App\Module\Metadata\Application\Handler\ConfigureObjectSearchHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'metadata:object:search', description: 'Configure how the records of an object are searched')]
final class ConfigureObjectSearchConsoleCommand
{
    use ReportsViolations;

    public function __construct(
        private readonly ConfigureObjectSearchHandler $handler,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Object machine name')] string $object,
        #[Option('Search handler, e.g. "free_text_multi_field" or "phrase" (default when omitted)')] ?string $handler = null,
        #[Option('Comma separated fields to search (default when omitted: every text-like field)')] ?string $fields = null,
    ): int {
        $fieldList = null === $fields ? null : array_values(array_filter(array_map(trim(...), explode(',', $fields)), static fn (string $field): bool => '' !== $field));

        $command = new ConfigureObjectSearchCommand($object, $handler, $fieldList);
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
            'Search of "%s": handler %s, fields %s.',
            $object,
            $handler ?? '(default)',
            null === $fieldList ? '(every text-like field)' : implode(', ', $fieldList),
        ));

        return Command::SUCCESS;
    }
}
