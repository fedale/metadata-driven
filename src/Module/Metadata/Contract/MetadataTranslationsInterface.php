<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Dto\TranslationEntryDto;

/**
 * The texts of the metadata and their translations, for the modules that edit them (the
 * admin UI). Changes go through MetadataManagerInterface::translate().
 */
interface MetadataTranslationsInterface
{
    /**
     * The language the metadata is written in.
     */
    public function defaultLocale(): string;

    /**
     * @return list<string> the enabled languages, the default one first
     */
    public function locales(): array;

    /**
     * Every translatable text with its translation in a language: the labels of the objects,
     * fields and options, the titles of the forms and of their sections, then the texts.
     *
     * @return list<TranslationEntryDto>
     */
    public function entries(string $locale): array;
}
