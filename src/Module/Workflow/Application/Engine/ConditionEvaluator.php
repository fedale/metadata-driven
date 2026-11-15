<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Workflow\Application\Definition\Condition;

/**
 * Whether a condition holds on a run's data chain, with the operators of the grid's filters.
 * Numbers compare as numbers, anything else as text (ISO dates sort as text); a list holds a
 * value when one of its items equals it.
 */
final class ConditionEvaluator
{
    public function holds(Condition $condition, DataChain $chain): bool
    {
        if ('all' === $condition->group) {
            return [] === array_filter($condition->conditions, fn (Condition $nested): bool => !$this->holds($nested, $chain));
        }
        if ('any' === $condition->group) {
            return [] !== array_filter($condition->conditions, fn (Condition $nested): bool => $this->holds($nested, $chain));
        }
        $left = $chain->get((string) $condition->path);
        $right = $chain->render($condition->value);

        return match ($condition->operator) {
            'eq' => self::equals($left, $right),
            'neq' => !self::equals($left, $right),
            'lt' => null !== $left && self::compare($left, $right) < 0,
            'lte' => null !== $left && self::compare($left, $right) <= 0,
            'gt' => null !== $left && self::compare($left, $right) > 0,
            'gte' => null !== $left && self::compare($left, $right) >= 0,
            'contains' => self::contains($left, $right),
            'not_contains' => !self::contains($left, $right),
            'starts_with' => \is_scalar($left) && \is_scalar($right) && str_starts_with(mb_strtolower((string) $left), mb_strtolower((string) $right)),
            'ends_with' => \is_scalar($left) && \is_scalar($right) && str_ends_with(mb_strtolower((string) $left), mb_strtolower((string) $right)),
            'in' => \is_array($right) && [] !== array_filter($right, static fn (mixed $item): bool => self::equals($left, $item)),
            'between' => \is_array($right) && null !== $left && self::compare($left, $right[0] ?? null) >= 0 && self::compare($left, $right[1] ?? null) <= 0,
            'empty' => self::isEmpty($left),
            'not_empty' => !self::isEmpty($left),
            default => false,
        };
    }

    private static function equals(mixed $left, mixed $right): bool
    {
        if (\is_array($left)) {
            return [] !== array_filter($left, static fn (mixed $item): bool => self::equals($item, $right));
        }
        if (null === $left || null === $right) {
            return null === $left && null === $right;
        }
        if (\is_bool($left) || \is_bool($right)) {
            return self::flag($left) === self::flag($right);
        }

        return is_numeric($left) && is_numeric($right) ? (float) $left === (float) $right : DataChain::asText($left) === DataChain::asText($right);
    }

    private static function compare(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return strcmp(\is_scalar($left) ? (string) $left : '', \is_scalar($right) ? (string) $right : '');
    }

    private static function contains(mixed $left, mixed $right): bool
    {
        if (\is_array($left)) {
            return self::equals($left, $right);
        }

        return \is_scalar($left) && \is_scalar($right) && '' !== (string) $right && str_contains(mb_strtolower((string) $left), mb_strtolower((string) $right));
    }

    private static function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value || [] === $value;
    }

    private static function flag(mixed $value): bool
    {
        return \is_bool($value) ? $value : \in_array(\is_scalar($value) ? strtolower((string) $value) : '', ['1', 'true', 'on', 'yes'], true);
    }
}
