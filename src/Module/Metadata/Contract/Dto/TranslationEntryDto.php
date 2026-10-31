<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A translatable text: its key, its text in the default language and in one other language.
 */
final readonly class TranslationEntryDto
{
    /**
     * @param string       $group       what the text belongs to, for grouping: "Object pet", "Form registration", "Texts"
     * @param string       $source      the text in the default language
     * @param string|null  $translation the text in the language asked for; null when not translated
     * @param list<string> $usedBy      for a text key: where a {t:name} token places it, e.g. "view pet/main"
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $source,
        public ?string $translation,
        public array $usedBy = [],
    ) {
    }

    public function isText(): bool
    {
        return TranslationKey::isText($this->key);
    }
}
