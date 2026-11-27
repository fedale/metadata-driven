<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid\Renderer;

use App\Shared\Message\Message;

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
        $errors = array_map(static fn (string $key): Message => new Message('metadata.grid_view.renderer_option_unknown', 'unknown option "%option%" of renderer %renderer% (allowed: %allowed%).', ['option' => $key, 'renderer' => 'truncate', 'allowed' => 'length']), array_values(array_diff(array_keys($options), ['length'])));
        $length = $options['length'] ?? self::DEFAULT_LENGTH;
        if (!\is_int($length) || $length < 1 || $length > self::MAX_LENGTH) {
            $errors[] = new Message('metadata.grid_view.truncate_length_invalid', '"length" must be an integer from 1 to %max%.', ['max' => self::MAX_LENGTH]);
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
