<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\Record\Contract\Event\RecordWriteOrigin;
use App\Module\Record\Contract\RecordEventScopeInterface;
use Symfony\Contracts\Service\ResetInterface;

final class RecordEventScope implements RecordEventScopeInterface, ResetInterface
{
    private bool $emits = true;

    private ?RecordWriteOrigin $origin = null;

    private bool $package = false;

    public function asRun(string $runId, int $depth, bool $emitEvents, callable $operation): mixed
    {
        return $this->within($emitEvents && $this->emits, new RecordWriteOrigin($runId, $depth), $operation);
    }

    public function withoutEvents(callable $operation): mixed
    {
        $previous = $this->package;
        $this->package = true;
        try {
            return $this->within(false, $this->origin, $operation);
        } finally {
            $this->package = $previous;
        }
    }

    public function isPackageWrite(): bool
    {
        return $this->package;
    }

    public function emitsEvents(): bool
    {
        return $this->emits;
    }

    public function origin(): ?RecordWriteOrigin
    {
        return $this->origin;
    }

    public function reset(): void
    {
        $this->emits = true;
        $this->origin = null;
        $this->package = false;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function within(bool $emits, ?RecordWriteOrigin $origin, callable $operation): mixed
    {
        [$previousEmits, $previousOrigin] = [$this->emits, $this->origin];
        [$this->emits, $this->origin] = [$emits, $origin];
        try {
            return $operation();
        } finally {
            [$this->emits, $this->origin] = [$previousEmits, $previousOrigin];
        }
    }
}
