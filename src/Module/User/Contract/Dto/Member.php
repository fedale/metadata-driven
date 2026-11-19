<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Dto;

/**
 * A user who may sign in to a workspace, as the members page lists them.
 */
final readonly class Member
{
    /**
     * @param string $displayName the profile's full name, or the email
     */
    public function __construct(
        public string $email,
        public string $displayName,
        public ?\DateTimeImmutable $lastLoginAt,
        /** The language chosen in the profile; null: none chosen. */
        public ?string $locale = null,
        /** Whether the notifications come by email too. */
        public bool $notifyByEmail = false,
        /** False for an account disabled: it signs in nowhere. */
        public bool $active = true,
    ) {
    }
}
