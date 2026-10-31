<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Repository;

use App\Module\Metadata\Domain\Model\Translation;

interface TranslationRepository
{
    public function save(Translation $translation): void;

    public function remove(Translation $translation): void;

    public function find(string $locale, string $key): ?Translation;

    /**
     * @return array<string, string> text by key, in one language
     */
    public function textsOf(string $locale): array;

    /**
     * Removes the key in every language.
     */
    public function removeKey(string $key): void;

    /**
     * Removes, in every language, the keys equal to one of the prefixes or starting with it
     * followed by a dot.
     *
     * @param list<string> $prefixes
     */
    public function removeKeysUnder(array $prefixes): void;
}
