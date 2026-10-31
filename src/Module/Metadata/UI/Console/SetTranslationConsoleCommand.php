<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:translation:set', description: 'Set the text of a translation key in a language, or remove it (no text)')]
final class SetTranslationConsoleCommand
{
    public function __construct(
        private readonly MetadataManagerInterface $manager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Language, one of the enabled locales (e.g. "it")')] string $locale,
        #[Argument('Key: "field.<object>.<field>", "option.<object>.<field>.<value>", "text.<name>"... (see metadata:translation:list)')] string $key,
        #[Argument('The text; omitted or empty: remove it')] ?string $text = null,
    ): int {
        try {
            $this->manager->translate($locale, $key, $text);
        } catch (MetadataException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(null === $text || '' === trim($text)
            ? \sprintf('"%s" removed in %s.', $key, $locale)
            : \sprintf('"%s" set in %s.', $key, $locale));

        return Command::SUCCESS;
    }
}
