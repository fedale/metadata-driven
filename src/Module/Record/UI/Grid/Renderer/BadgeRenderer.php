<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid\Renderer;

use App\Shared\Message\Message;

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
        $errors = array_map(static fn (string $key): Message => new Message('metadata.grid_view.renderer_option_unknown', 'unknown option "%option%" of renderer %renderer% (allowed: %allowed%).', ['option' => $key, 'renderer' => 'badge', 'allowed' => 'tones']), array_values(array_diff(array_keys($options), ['tones'])));
        $tones = $options['tones'] ?? [];
        if (!\is_array($tones) || ([] !== $tones && array_is_list($tones))) {
            return [...$errors, new Message('metadata.grid_view.badge_tones_invalid', '"tones" must be a map of value => tone.')];
        }
        foreach ($tones as $value => $tone) {
            if (!\in_array($tone, self::TONES, true)) {
                $errors[] = new Message('metadata.grid_view.badge_tone_invalid', 'the tone of "%value%" must be one of %tones%.', ['value' => (string) $value, 'tones' => implode(', ', self::TONES)]);
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
