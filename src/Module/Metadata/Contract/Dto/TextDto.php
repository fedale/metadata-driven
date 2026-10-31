<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A text of its own ("text.<name>"), placed by {t:name} tokens, in every language.
 */
final readonly class TextDto
{
    /**
     * @param array<string, string> $texts  by locale; the default language is always there
     * @param list<string>          $usedBy where a {t:name} token places it, e.g. "form view pet/main"
     */
    public function __construct(
        public string $name,
        public array $texts,
        public array $usedBy,
    ) {
    }

    public function key(): string
    {
        return TranslationKey::text($this->name);
    }
}
