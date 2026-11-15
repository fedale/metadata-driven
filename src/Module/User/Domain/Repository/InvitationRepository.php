<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Repository;

use App\Module\User\Contract\Dto\Invitation;

/**
 * core.workspace_invitation: every query names its workspace. Tokens are looked up by their SHA-256.
 */
interface InvitationRepository
{
    public function add(Invitation $invitation, string $workspaceId, string $tokenHash): void;

    /**
     * The workspace's pending invitation of an email, if any (one at a time per email).
     */
    public function pendingOf(string $workspaceId, string $email, \DateTimeImmutable $now, int $maxFailures): ?Invitation;

    /**
     * Deletes the workspace's pending invitations of an email: one at a time per email.
     */
    public function removePendingOf(string $workspaceId, string $email): void;

    /**
     * @return list<Invitation> not accepted, not expired, not burned; the newest first
     */
    public function pending(string $workspaceId, \DateTimeImmutable $now, int $maxFailures): array;

    /**
     * A pending invitation by its token's SHA-256, in the workspace only.
     */
    public function findPending(string $workspaceId, string $tokenHash, \DateTimeImmutable $now, int $maxFailures): ?Invitation;

    public function markAccepted(string $id, \DateTimeImmutable $at): void;

    /**
     * Takes one of the invitation's tries, before the password is checked, in one statement:
     * concurrent requests can't take more than are left.
     *
     * @return bool false when none is left (the invitation is burned)
     */
    public function takeAttempt(string $id, int $maxAttempts): bool;

    /**
     * @return bool false when the workspace has no such invitation
     */
    public function remove(string $workspaceId, string $id): bool;
}
