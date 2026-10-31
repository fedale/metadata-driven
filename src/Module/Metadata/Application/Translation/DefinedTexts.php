<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Translation;

use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks the {t:name} tokens of a layout or a heading: each names a text defined in the
 * default language. Read when a definition is saved, not on every page.
 */
final class DefinedTexts
{
    public function __construct(
        private readonly TranslationRepository $translations,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * @return list<string> the names of the tokens without a text, in order, once each
     */
    public function undefinedIn(string $text): array
    {
        $names = array_values(array_unique(ViewDefinitionDto::textTokens($text)));
        if ([] === $names) {
            return [];
        }
        $texts = $this->translations->textsOf($this->defaultLocale);

        return array_values(array_filter($names, static fn (string $name): bool => !isset($texts[TranslationKey::text($name)])));
    }

    /**
     * The error for the tokens without a text, prefixed by where they are; null when there is none.
     */
    public function errorFor(string $where, string $text): ?string
    {
        $undefined = $this->undefinedIn($text);

        return [] === $undefined ? null : \sprintf(
            '%s: %s %s no text: define %s in %s first (Admin > Translations).',
            $where,
            implode(', ', array_map(static fn (string $name): string => '{t:'.$name.'}', $undefined)),
            1 === \count($undefined) ? 'is' : 'are',
            implode(', ', array_map(static fn (string $name): string => '"'.TranslationKey::text($name).'"', $undefined)),
            $this->defaultLocale,
        );
    }
}
