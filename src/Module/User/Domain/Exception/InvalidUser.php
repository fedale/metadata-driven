<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Exception;

use App\Module\User\Contract\Exception\UserException;

final class InvalidUser extends UserException
{
    public static function invalidEmail(string $email): self
    {
        return new self(\sprintf('"%s" is not a valid email address.', $email));
    }

    public static function emailTaken(string $email): self
    {
        return new self(\sprintf('A user with the email "%s" already exists.', $email));
    }

    public static function passwordTooShort(int $minLength): self
    {
        return new self(\sprintf('The password must have at least %d characters.', $minLength));
    }
}
