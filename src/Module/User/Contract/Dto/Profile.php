<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Dto;

/**
 * The signed-in user's own account, as the account page shows it.
 */
final readonly class Profile
{
    /** The colour themes a user may force; none chosen: the system's. */
    public const array THEMES = ['light', 'dark'];

    public function __construct(
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
        /** Whether the notifications come by email too. */
        public bool $notifyByEmail = false,
        /** The colour theme, one of THEMES; null: the system's. */
        public ?string $theme = null,
    ) {
    }
}
