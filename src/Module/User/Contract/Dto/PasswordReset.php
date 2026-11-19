<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Dto;

/**
 * A password reset just asked for an account: what its email needs. The token is in the link
 * only, never stored.
 */
final readonly class PasswordReset
{
    public function __construct(
        /** The account's email, as stored. */
        public string $email,
        public string $token,
        public \DateTimeImmutable $expiresAt,
        /** The language chosen in the account's profile; null: none chosen. */
        public ?string $locale,
    ) {
    }
}
