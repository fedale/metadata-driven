<?php

declare(strict_types=1);

namespace App\Module\Record\Application\State;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The transition under way, if any: the one write that may change a Status (StateGuard).
 */
final class TransitionContext implements ResetInterface
{
    /** @var array{string, string, string, string}|null object, record id, field, state reached */
    private ?array $applying = null;

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function run(string $object, string $id, string $field, string $to, callable $write): mixed
    {
        $previous = $this->applying;
        $this->applying = [$object, $id, $field, $to];
        try {
            return $write();
        } finally {
            $this->applying = $previous;
        }
    }

    public function allows(string $object, ?string $id, string $field, mixed $value): bool
    {
        return null !== $this->applying && [$object, $id, $field, $value] === $this->applying;
    }

    public function reset(): void
    {
        $this->applying = null;
    }
}
