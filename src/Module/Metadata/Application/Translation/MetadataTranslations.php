<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Translation;

use App\Module\Metadata\Contract\Dto\TranslationEntryDto;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MetadataTranslations implements MetadataTranslationsInterface
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly TranslationKeys $keys,
        private readonly TranslationRepository $translations,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function locales(): array
    {
        return array_values(array_unique([$this->defaultLocale, ...$this->enabledLocales]));
    }

    public function entries(string $locale): array
    {
        $texts = $this->translations->textsOf($locale);
        $defaults = $locale === $this->defaultLocale ? $texts : $this->translations->textsOf($this->defaultLocale);

        $entries = [];
        foreach ($this->keys->derived() as $key => $entry) {
            // In the default language the metadata is the text: there is nothing to translate.
            $translation = $locale === $this->defaultLocale ? null : ($texts[$key] ?? null);
            $entries[] = new TranslationEntryDto($key, $entry['group'], $entry['source'], $translation);
        }

        $usages = $this->keys->textUsages();
        $names = [];
        foreach ($defaults as $key => $text) {
            if (TranslationKey::isText($key)) {
                $names[substr($key, \strlen(TranslationKey::TEXT))] = $text;
            }
        }
        ksort($names);
        foreach ($names as $name => $text) {
            $key = TranslationKey::text((string) $name);
            $entries[] = new TranslationEntryDto($key, 'Texts', $text, $locale === $this->defaultLocale ? $text : ($texts[$key] ?? null), $usages[$name] ?? []);
        }

        return $entries;
    }
}
