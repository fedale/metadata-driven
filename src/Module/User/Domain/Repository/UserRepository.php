<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Repository;

use App\Module\User\Domain\Model\User;

interface UserRepository
{
    /**
     * Saves the user and its profile.
     */
    public function save(User $user): void;

    /**
     * @param string $email any case
     */
    public function findByEmail(string $email): ?User;

    /**
     * @return list<User> by email
     */
    public function operators(): array;
}
