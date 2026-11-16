<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Event;

/**
 * A user became a member of a workspace: an invitation accepted, workspace:member:add, a user
 * created in a workspace. Not raised for one who was a member already.
 */
final readonly class MemberAdded
{
    public function __construct(
        public string $email,
        public string $workspaceId,
    ) {
    }
}
