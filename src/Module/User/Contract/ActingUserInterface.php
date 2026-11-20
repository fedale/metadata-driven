<?php

declare(strict_types=1);

namespace App\Module\User\Contract;

use App\Module\User\Contract\Exception\NotAMember;

/**
 * Work done for a user where no one is signed in, the worker's: as if they were, with their roles
 * in the current workspace, so the permissions checked, the author of a write and $user of the
 * workflows are theirs.
 */
interface ActingUserInterface
{
    /**
     * Runs $work signed in as the user, then gives back who was signed in before (no one, in the
     * worker).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws NotAMember the user is unknown, deactivated, or no member of the current workspace
     */
    public function actAs(string $email, callable $work): mixed;
}
