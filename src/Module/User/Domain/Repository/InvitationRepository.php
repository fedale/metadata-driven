<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Repository;

use App\Module\User\Contract\Dto\Invitation;

/**
 * core.tenant_invitation: every query names its tenant. Tokens are looked up by their SHA-256.
 */
interface InvitationRepository
{
    public function add(Invitation $invitation, string $tenantId, string $tokenHash): void;

    /**
     * @return list<Invitation> not accepted, not expired, not burned; the newest first
     */
    public function pending(string $tenantId, \DateTimeImmutable $now, int $maxFailures): array;

    /**
     * A pending invitation by its token's SHA-256, in the tenant only.
     */
    public function findPending(string $tenantId, string $tokenHash, \DateTimeImmutable $now, int $maxFailures): ?Invitation;

    public function markAccepted(string $id, \DateTimeImmutable $at): void;

    public function recordFailure(string $id): void;

    /**
     * @return bool false when the tenant has no such invitation
     */
    public function remove(string $tenantId, string $id): bool;
}
