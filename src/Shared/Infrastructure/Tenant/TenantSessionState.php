<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether this process works as the system, remembered for the connection: a system
 * command (the migrations) may select the system before the connection is opened, and a
 * connection opened again must not come back at its baseline (no rows) in the middle of it.
 *
 * Kept apart from TenantSwitcher, which needs the connection: the connection's middleware needs
 * this.
 */
final class TenantSessionState implements ResetInterface
{
    private bool $system = false;

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function setSystem(bool $system): void
    {
        $this->system = $system;
    }

    public function reset(): void
    {
        $this->system = false;
    }
}
