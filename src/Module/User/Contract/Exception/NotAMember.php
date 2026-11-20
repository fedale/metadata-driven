<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Exception;

final class NotAMember extends UserException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct(\sprintf('%s is no active member of this workspace.', $email));
    }
}
