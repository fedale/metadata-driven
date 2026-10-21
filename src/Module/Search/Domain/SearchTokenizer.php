<?php

declare(strict_types=1);

namespace App\Module\Search\Domain;

/**
 * Splits a search term into lowercase tokens: words, or "quoted phrases" kept whole.
 */
final class SearchTokenizer
{
    /** Bounds the size of the generated query, whatever the user pastes in the box. */
    public const int MAX_TOKENS = 8;

    /**
     * @return list<string> unique tokens, in input order
     */
    public static function tokenize(string $term): array
    {
        preg_match_all('/"([^"]*)"|(\S+)/u', mb_strtolower($term), $matches, \PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            // A quoted phrase keeps its inner spaces, normalized to one.
            $token = '' !== ($match[1] ?? '') ? (string) preg_replace('/\s+/u', ' ', trim($match[1])) : ($match[2] ?? '');
            if ('' !== $token && !\in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return \array_slice($tokens, 0, self::MAX_TOKENS);
    }
}
