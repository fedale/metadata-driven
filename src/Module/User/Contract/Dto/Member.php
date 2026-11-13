<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Dto;

/**
 * A user who may sign in to a tenant, as the members page lists them.
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
    ) {
    }
}
