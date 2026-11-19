<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Repository;

use Symfony\Component\Uid\Uuid;

/**
 * core.password_reset: accounts are global, so are their resets. Tokens are looked up by their
 * SHA-256.
 */
interface PasswordResetRepository
{
    /**
     * The account's new reset, in place of any it had pending: one at a time.
     */
    public function replace(Uuid $userId, string $tokenHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): void;

    /**
     * The email of the account a pending reset is for: not used, not expired. Null otherwise.
     */
    public function pendingEmail(string $tokenHash, \DateTimeImmutable $now): ?string;

    /**
     * Uses the pending reset, once: false when another request used it first.
     */
    public function use(string $tokenHash, \DateTimeImmutable $now): bool;
}
