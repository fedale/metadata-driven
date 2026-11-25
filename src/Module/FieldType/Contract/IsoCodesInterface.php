<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract;

/**
 * The code of a country, a language or a currency read from a file someone wrote: the code
 * itself in any case, or its name, in the current language or in English, ignoring case
 * ("Italia", "italy" → "IT"). For the CSV import and the package seeds; a record written
 * through the API or a form takes the code only.
 */
interface IsoCodesInterface
{
    /**
     * @param string $fieldType the field's type: "country", "language" or "currency"
     *
     * @return string|null the code in its case; null when the type takes no code, or nothing
     *                     matches (the value is then left to the field type to refuse)
     */
    public function code(string $fieldType, string $value): ?string;
}
