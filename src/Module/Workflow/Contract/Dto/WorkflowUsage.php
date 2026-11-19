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
        /** The emails its workflows sent this month, one per recipient. */
        public int $emails = 0,
        /** The emails its plan allows a month; null for no limit. */
        public ?int $emailLimit = null,
    ) {
    }

    /**
     * The emails left this month; null with no limit.
     */
    public function emailsLeft(): ?int
    {
        return null === $this->emailLimit ? null : max(0, $this->emailLimit - $this->emails);
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
