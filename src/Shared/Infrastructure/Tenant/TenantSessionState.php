<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether this process works as the landlord, remembered for the connection: a landlord's
 * command (the migrations) may select the landlord before the connection is opened, and a
 * connection opened again must not come back at its baseline (no rows) in the middle of it.
 *
 * Kept apart from TenantSwitcher, which needs the connection: the connection's middleware needs
 * this.
 */
final class TenantSessionState implements ResetInterface
{
    private bool $landlord = false;

    public function isLandlord(): bool
    {
        return $this->landlord;
    }

    public function setLandlord(bool $landlord): void
    {
        $this->landlord = $landlord;
    }

    public function reset(): void
    {
        $this->landlord = false;
    }
}
