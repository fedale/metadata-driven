<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:translation:list', description: 'List the translation keys of the metadata, with their text in the default language and in another one')]
final class ListTranslationsConsoleCommand
{
    public function __construct(
        private readonly MetadataTranslationsInterface $translations,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Language, one of the enabled locales; default: the default language')] ?string $locale = null,
        #[Option('Only the keys without a text in that language')] bool $missing = false,
    ): int {
        $default = $this->translations->defaultLocale();
        $locale ??= $default;
        if (!\in_array($locale, $this->translations->locales(), true)) {
            $io->error(\sprintf('"%s" is no enabled language (%s).', $locale, implode(', ', $this->translations->locales())));

            return Command::INVALID;
        }

        $rows = [];
        foreach ($this->translations->entries($locale) as $entry) {
            if ($missing && (null !== $entry->translation || $locale === $default)) {
                continue;
            }
            $rows[] = $locale === $default
                ? [$entry->key, $entry->source]
                : [$entry->key, $entry->source, $entry->translation ?? '<comment>(missing)</comment>'];
        }
        $io->table($locale === $default ? ['Key', $default] : ['Key', $default, $locale], $rows);

        return Command::SUCCESS;
    }
}
