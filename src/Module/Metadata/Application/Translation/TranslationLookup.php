<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Translation;

use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;

/**
 * The texts of one language, with the default language behind them.
 *
 * A key without a translation keeps the text of the metadata. A {t:name} token falls back on
 * the text in the default language, then on its own name: a token is checked when it is saved,
 * so the name shows only if its text was removed since.
 */
final readonly class TranslationLookup
{
    /**
     * @param array<string, string> $messages the texts of the language, by key
     * @param array<string, string> $defaults the texts of the default language, by key (text keys only matter)
     */
    public function __construct(
        private array $messages,
        private array $defaults,
    ) {
    }

    public function translate(string $key, string $default): string
    {
        return $this->messages[$key] ?? $default;
    }

    public function text(string $name): string
    {
        $key = TranslationKey::text($name);

        return $this->messages[$key] ?? $this->defaults[$key] ?? $name;
    }

    /**
     * Replaces the {t:name} tokens of an HTML layout with their texts, escaped.
     */
    public function layout(string $html): string
    {
        return (string) preg_replace_callback(
            ViewDefinitionDto::TEXT_TOKEN_PATTERN,
            fn (array $token): string => htmlspecialchars($this->text($token[1]), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            $html,
        );
    }

    /**
     * Replaces the {t:name} tokens of a plain text (a grid heading) with their texts.
     */
    public function plain(string $text): string
    {
        return (string) preg_replace_callback(
            ViewDefinitionDto::TEXT_TOKEN_PATTERN,
            fn (array $token): string => $this->text($token[1]),
            $text,
        );
    }
}
