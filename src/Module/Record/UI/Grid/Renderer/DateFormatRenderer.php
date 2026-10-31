<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid\Renderer;

/**
 * Formats a date or a date and time with a PHP date "format" ("d/m/Y" by default).
 */
final class DateFormatRenderer implements CellRendererInterface
{
    private const string DEFAULT_FORMAT = 'd/m/Y';

    public function name(): string
    {
        return 'date';
    }

    public function optionErrors(array $options): array
    {
        $errors = array_map(static fn (string $key): string => \sprintf('unknown option "%s" of renderer date (allowed: format).', $key), array_values(array_diff(array_keys($options), ['format'])));
        $format = $options['format'] ?? self::DEFAULT_FORMAT;
        if (!\is_string($format) || '' === trim($format) || \strlen($format) > 50) {
            $errors[] = '"format" must be a PHP date format, e.g. "d/m/Y H:i".';
        }

        return $errors;
    }

    public function render(mixed $value, string $text, array $options): string
    {
        if (\is_string($value) && '' !== $value) {
            try {
                $value = new \DateTimeImmutable($value);
            } catch (\Exception) {
                return htmlspecialchars($text, \ENT_QUOTES);
            }
        }
        if (!$value instanceof \DateTimeInterface) {
            return htmlspecialchars($text, \ENT_QUOTES);
        }

        return htmlspecialchars($value->format(\is_string($options['format'] ?? null) ? $options['format'] : self::DEFAULT_FORMAT), \ENT_QUOTES);
    }
}
