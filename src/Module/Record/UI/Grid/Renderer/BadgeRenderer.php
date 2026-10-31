<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid\Renderer;

/**
 * Shows the value as a badge (gv-badge of fedale/gridview-bundle), its tone chosen by value:
 * "tones: { active: success, suspended: danger }". Values without a tone stay neutral.
 */
final class BadgeRenderer implements CellRendererInterface
{
    public const array TONES = ['neutral', 'info', 'success', 'warning', 'danger'];

    public function name(): string
    {
        return 'badge';
    }

    public function optionErrors(array $options): array
    {
        $errors = array_map(static fn (string $key): string => \sprintf('unknown option "%s" of renderer badge (allowed: tones).', $key), array_values(array_diff(array_keys($options), ['tones'])));
        $tones = $options['tones'] ?? [];
        if (!\is_array($tones) || ([] !== $tones && array_is_list($tones))) {
            return [...$errors, '"tones" must be a map of value => tone.'];
        }
        foreach ($tones as $value => $tone) {
            if (!\in_array($tone, self::TONES, true)) {
                $errors[] = \sprintf('the tone of "%s" must be one of %s.', $value, implode(', ', self::TONES));
            }
        }

        return $errors;
    }

    public function render(mixed $value, string $text, array $options): string
    {
        if ('' === $text) {
            return '';
        }
        $tones = \is_array($options['tones'] ?? null) ? $options['tones'] : [];
        $key = \is_bool($value) ? ($value ? 'true' : 'false') : (\is_scalar($value) ? (string) $value : '');
        $tone = $tones[$key] ?? 'neutral';

        $class = \is_string($tone) && 'neutral' !== $tone ? ' gv-badge--tone-'.$tone : '';

        return \sprintf('<span class="gv-badge%s">%s</span>', $class, htmlspecialchars($text, \ENT_QUOTES));
    }
}
