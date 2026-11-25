<?php

declare(strict_types=1);

namespace App\Module\FieldType\Application;

use App\Module\FieldType\Contract\IsoCodesInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Languages;

/**
 * Codes from symfony/intl's lists, by code or by name in the current language (the one the
 * request or the console runs in) and in English.
 */
final class IntlIsoCodes implements IsoCodesInterface
{
    /** @var array<string, array<string, string>> lower-case code or name => code, by type and locale */
    private array $lookups = [];

    public function code(string $fieldType, string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        if ('' === $value || !\in_array($fieldType, ['country', 'language', 'currency'], true)) {
            return null;
        }

        return $this->lookup($fieldType, \Locale::getDefault())[$value] ?? $this->lookup($fieldType, 'en')[$value] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function lookup(string $fieldType, string $locale): array
    {
        $key = $fieldType.'|'.$locale;
        if (!isset($this->lookups[$key])) {
            $names = match ($fieldType) {
                'country' => Countries::getNames($locale),
                'language' => Languages::getNames($locale),
                default => Currencies::getNames($locale),
            };
            $lookup = [];
            foreach ($names as $code => $name) {
                $lookup[mb_strtolower($name)] ??= $code;
            }
            // A code wins over a name that happens to read the same.
            foreach (array_keys($names) as $code) {
                $lookup[mb_strtolower($code)] = $code;
            }
            $this->lookups[$key] = $lookup;
        }

        return $this->lookups[$key];
    }
}
