<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract\Dto;

/**
 * A workspace's workflow steps this calendar month, against what its plan allows.
 */
final readonly class WorkflowUsage
{
    public function __construct(
        public string $plan,
        public int $steps,
        /** The steps its plan allows a month; null for no limit. */
        public ?int $limit,
    ) {
    }

    /**
     * Its share of the month's steps spent, 0 to 100 and beyond; null with no limit.
     */
    public function percent(): ?int
    {
        return null === $this->limit ? null : (0 === $this->limit ? 100 : (int) floor($this->steps * 100 / $this->limit));
    }

    public function exhausted(): bool
    {
        return null !== $this->limit && $this->steps >= $this->limit;
    }
}
