<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * Whether a new version's number fits what it changed, against the version installed (whatever
 * the instance changed meanwhile):
 *
 * - a new major version may change anything;
 * - a minor one may add items and change their settings, conditions, search, unique sets, but
 *   removes and renames nothing, and carries no migration steps;
 * - a patch only changes labels, layouts, forms and translations.
 *
 * A template whose number lies about its changes is refused: who installs a minor version
 * expects to lose nothing.
 */
final class VersionCheck
{
    /**
     * @param array<string, ItemState> $base
     * @param array<string, ItemState> $theirs
     *
     * @return list<string> what does not fit, each with its item
     */
    public static function problems(array $base, array $theirs, string $from, string $to, bool $migrates): array
    {
        [$major, $minor] = self::bump($from, $to);
        if ($major) {
            return [];
        }

        $problems = [];
        $needs = static function (string $what, ItemState $state, string $version) use (&$problems, $to): void {
            $problems[] = \sprintf('%s: %s %s: %s needs a new %s version, %s is not one.', $state->document->path, $state->kind, $state->id, $what, $version, $to);
        };
        if ($migrates) {
            $problems[] = \sprintf('template.yaml: spec.migrations: migration steps need a new major version, %s is not one.', $to);
        }
        foreach ($base as $key => $state) {
            if (!isset($theirs[$key]) && 'translation' !== $state->kind) {
                $needs('a removal', $state, 'major');
            }
        }
        foreach ($theirs as $key => $state) {
            $old = $base[$key] ?? null;
            if (null === $old) {
                if (!$minor && 'translation' !== $state->kind) {
                    $needs('an addition', $state, 'minor');
                }
                continue;
            }
            if ($old->equals($state) || !\is_array($old->value) || !\is_array($state->value)) {
                continue;
            }
            $changed = array_keys(array_filter($state->value, static fn (mixed $value, int|string $key): bool => ($old->value[$key] ?? null) !== $value, \ARRAY_FILTER_USE_BOTH));
            if (\in_array('name', $changed, true) && \in_array($state->kind, ['object', 'field'], true)) {
                $needs('a rename', $state, 'major');
            } elseif (!$minor && \in_array($state->kind, ['object', 'field'], true) && [] !== array_diff($changed, ['label', 'plural'])) {
                $needs(\sprintf('a change of %s', implode(', ', array_diff($changed, ['label', 'plural']))), $state, 'minor');
            }
        }

        return $problems;
    }

    /**
     * @return array{bool, bool} whether the major part grew, whether the minor part did
     */
    private static function bump(string $from, string $to): array
    {
        $old = array_map(intval(...), explode('.', $from));
        $new = array_map(intval(...), explode('.', $to));

        return [$new[0] > $old[0], $new[0] === $old[0] && ($new[1] ?? 0) > ($old[1] ?? 0)];
    }
}
