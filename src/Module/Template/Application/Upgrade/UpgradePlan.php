<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * The changes an upgrade brings, item by item, from the installed version (base), the new one
 * (theirs) and what the instance holds now (mine):
 *
 * - an item theirs changed and mine did not: theirs is applied;
 * - an item mine changed and theirs did not: mine stays (no change);
 * - an item both changed, differently: a conflict;
 * - an item new in theirs: added; gone from theirs: removed, unless mine changed it (a conflict).
 *
 * Items only mine has (a field the user added to an object of the template) are not the
 * template's: no plan ever touches them.
 */
final readonly class UpgradePlan
{
    /**
     * @param list<Change> $changes
     */
    private function __construct(
        public array $changes,
    ) {
    }

    /**
     * @param array<string, ItemState> $base
     * @param array<string, ItemState> $theirs
     * @param array<string, ItemState> $mine
     */
    public static function compute(array $base, array $theirs, array $mine): self
    {
        $changes = [];
        foreach (array_keys($base + $theirs) as $key) {
            $b = $base[$key] ?? null;
            $t = $theirs[$key] ?? null;
            $m = $mine[$key] ?? null;
            if (null !== $b && null !== $t) {
                if ($b->equals($t) || $t->equals($m)) {
                    continue;
                }
                $changes[] = new Change(ChangeAction::Change, $b, $t, $m, !$b->equals($m));
            } elseif (null !== $t) {
                if (!$t->equals($m)) {
                    $changes[] = new Change(ChangeAction::Add, null, $t, $m, null !== $m);
                }
            } elseif (null !== $b && null !== $m) {
                $changes[] = new Change(ChangeAction::Remove, $b, null, $m, !$b->equals($m));
            }
        }

        return new self($changes);
    }

    /**
     * @return list<Change>
     */
    public function conflicts(): array
    {
        return array_values(array_filter($this->changes, static fn (Change $change): bool => $change->conflict));
    }

    /**
     * The changes to apply: a conflict only when the new version is to win.
     *
     * @return list<Change>
     */
    public function toApply(?Strategy $strategy): array
    {
        return array_values(array_filter($this->changes, static fn (Change $change): bool => !$change->conflict || Strategy::Overwrite === $strategy));
    }
}
