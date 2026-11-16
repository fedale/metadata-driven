<?php

declare(strict_types=1);

namespace App\Module\Record\Application\State;

use App\Module\Record\Contract\Event\RecordTransition;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The transition under way, if any: the one write that may change a Status (StateGuard).
 */
final class TransitionContext implements ResetInterface
{
    /** @var array{string, string, string, string}|null object, record id, field, state reached */
    private ?array $applying = null;

    private ?RecordTransition $transition = null;

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function run(string $object, string $id, RecordTransition $transition, callable $write): mixed
    {
        [$previous, $previousTransition] = [$this->applying, $this->transition];
        [$this->applying, $this->transition] = [[$object, $id, $transition->field, $transition->to], $transition];
        try {
            return $write();
        } finally {
            [$this->applying, $this->transition] = [$previous, $previousTransition];
        }
    }

    /**
     * The transition of a write of that record, if one is under way.
     */
    public function of(string $object, ?string $id): ?RecordTransition
    {
        return null !== $this->applying && $object === $this->applying[0] && $id === $this->applying[1] ? $this->transition : null;
    }

    public function allows(string $object, ?string $id, string $field, mixed $value): bool
    {
        return null !== $this->applying && [$object, $id, $field, $value] === $this->applying;
    }

    public function reset(): void
    {
        $this->applying = null;
        $this->transition = null;
    }
}
