<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Contract\FieldTypeInterface;

/**
 * Rejects unknown setting keys and gives subclasses typed access to resolved settings.
 */
abstract class AbstractFieldType implements FieldTypeInterface
{
    public function defaultSettings(): array
    {
        return [];
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        foreach (array_keys(array_diff_key($settings, $this->defaultSettings())) as $key) {
            $errors[] = \sprintf('Unknown setting "%s".', $key);
        }

        return [...$errors, ...$this->validateKnownSettings($settings + $this->defaultSettings())];
    }

    public function constraints(array $settings): array
    {
        return [];
    }

    public function settingDefinitions(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $settings settings merged with defaults
     *
     * @return list<string>
     */
    protected function validateKnownSettings(array $settings): array
    {
        return [];
    }

    protected static function isIntBetween(mixed $value, int $min, int $max): bool
    {
        return \is_int($value) && $value >= $min && $value <= $max;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected static function intSetting(array $settings, string $key): int
    {
        $value = $settings[$key] ?? null;

        return \is_int($value) ? $value : throw new \LogicException(\sprintf('Setting "%s" was not validated as an integer.', $key));
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected static function boolSetting(array $settings, string $key): bool
    {
        $value = $settings[$key] ?? null;

        return \is_bool($value) ? $value : throw new \LogicException(\sprintf('Setting "%s" was not validated as a boolean.', $key));
    }

    /**
     * Converts scalars to a string; empty or blank strings become null.
     */
    protected static function toNullableString(mixed $value, bool $trim = true): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            throw new InvalidFieldValue('This value should be a string.');
        }

        $string = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        if ('' === trim($string)) {
            return null;
        }

        return $trim ? trim($string) : $string;
    }
}
