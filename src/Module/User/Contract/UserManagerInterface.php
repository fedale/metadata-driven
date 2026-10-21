<?php

declare(strict_types=1);

namespace App\Module\User\Contract;

use App\Module\User\Contract\Exception\UserException;

interface UserManagerInterface
{
    /**
     * Creates an active user with its profile. The email is the login, stored lowercase.
     *
     * @return string the id of the new user
     *
     * @throws UserException invalid or taken email, password too short
     */
    public function createUser(string $email, string $plainPassword, ?string $firstName = null, ?string $lastName = null): string;
}
