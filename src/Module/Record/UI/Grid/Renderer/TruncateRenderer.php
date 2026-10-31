<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid\Renderer;

/**
 * Cuts a long text to "length" characters (80 by default) and adds "…"; the whole text is
 * the cell's tooltip.
 */
final class TruncateRenderer implements CellRendererInterface
{
    private const int DEFAULT_LENGTH = 80;
    private const int MAX_LENGTH = 1000;

    public function name(): string
    {
        return 'truncate';
    }

    public function optionErrors(array $options): array
    {
        $errors = array_map(static fn (string $key): string => \sprintf('unknown option "%s" of renderer truncate (allowed: length).', $key), array_values(array_diff(array_keys($options), ['length'])));
        $length = $options['length'] ?? self::DEFAULT_LENGTH;
        if (!\is_int($length) || $length < 1 || $length > self::MAX_LENGTH) {
            $errors[] = \sprintf('"length" must be an integer from 1 to %d.', self::MAX_LENGTH);
        }

        return $errors;
    }

    public function render(mixed $value, string $text, array $options): string
    {
        $length = \is_int($options['length'] ?? null) ? $options['length'] : self::DEFAULT_LENGTH;
        if (mb_strlen($text) <= $length) {
            return htmlspecialchars($text, \ENT_QUOTES);
        }

        return \sprintf('<span title="%s">%s…</span>', htmlspecialchars($text, \ENT_QUOTES), htmlspecialchars(rtrim(mb_substr($text, 0, $length)), \ENT_QUOTES));
    }
}
