<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * A test on the data chain: a path compared with a value, or several tests of which all or any
 * must hold.
 */
final readonly class Condition
{
    /** The operators: the grid's filters, and two of their own. */
    public const array OPERATORS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'contains', 'not_contains', 'starts_with', 'ends_with', 'in', 'between', 'empty', 'not_empty'];

    /** Operators that take no value. */
    public const array UNARY = ['empty', 'not_empty'];

    /**
     * @param 'all'|'any'|null $group      null for a test of one path
     * @param list<self>       $conditions
     */
    private function __construct(
        public ?string $group,
        public array $conditions,
        public ?string $path,
        public ?string $operator,
        public mixed $value,
    ) {
    }

    public static function test(string $path, string $operator, mixed $value): self
    {
        return new self(null, [], $path, $operator, $value);
    }

    /**
     * @param 'all'|'any' $group
     * @param list<self>  $conditions
     */
    public static function group(string $group, array $conditions): self
    {
        return new self($group, $conditions, null, null, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (null !== $this->group) {
            return [$this->group => array_map(static fn (self $condition): array => $condition->toArray(), $this->conditions)];
        }

        return \in_array($this->operator, self::UNARY, true)
            ? ['field' => $this->path, 'op' => $this->operator]
            : ['field' => $this->path, 'op' => $this->operator, 'value' => $this->value];
    }
}
