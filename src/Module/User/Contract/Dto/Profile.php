<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Dto;

/**
 * The signed-in user's own account, as the account page shows it.
 */
final readonly class Profile
{
    public function __construct(
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
    ) {
    }
}
