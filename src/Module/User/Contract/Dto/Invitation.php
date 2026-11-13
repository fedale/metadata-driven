<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Dto;

/**
 * An invitation to a tenant, pending: the email as the admin typed it (normalized), never
 * anything of the account it may name.
 */
final readonly class Invitation
{
    /**
     * @param bool $keepAssignments false: the account's assignments in the tenant are taken away
     *                              on acceptance (its inviter doesn't hold them all)
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $invitedBy,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public bool $keepAssignments,
    ) {
    }
}
