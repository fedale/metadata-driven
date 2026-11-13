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
     * The tenant's pending invitation of an email, if any (one at a time per email).
     */
    public function pendingOf(string $tenantId, string $email, \DateTimeImmutable $now, int $maxFailures): ?Invitation;

    /**
     * Deletes the tenant's pending invitations of an email: one at a time per email.
     */
    public function removePendingOf(string $tenantId, string $email): void;

    /**
     * @return list<Invitation> not accepted, not expired, not burned; the newest first
     */
    public function pending(string $tenantId, \DateTimeImmutable $now, int $maxFailures): array;

    /**
     * A pending invitation by its token's SHA-256, in the tenant only.
     */
    public function findPending(string $tenantId, string $tokenHash, \DateTimeImmutable $now, int $maxFailures): ?Invitation;

    public function markAccepted(string $id, \DateTimeImmutable $at): void;

    /**
     * Takes one of the invitation's tries, before the password is checked, in one statement:
     * concurrent requests can't take more than are left.
     *
     * @return bool false when none is left (the invitation is burned)
     */
    public function takeAttempt(string $id, int $maxAttempts): bool;

    /**
     * @return bool false when the tenant has no such invitation
     */
    public function remove(string $tenantId, string $id): bool;
}
