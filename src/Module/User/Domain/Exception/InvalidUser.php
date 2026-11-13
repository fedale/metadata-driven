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

    /**
     * @param list<string> $enabled
     */
    public static function unknownLocale(string $locale, array $enabled): self
    {
        return new self(\sprintf('"%s" is no language of the application (%s).', $locale, implode(', ', $enabled)));
    }

    public static function wrongPassword(): self
    {
        return new self('This is not the password of the account.');
    }

    public static function invitationNotFound(): self
    {
        return new self('This invitation is no longer valid: ask for another one.');
    }

    public static function notFound(string $email): self
    {
        return new self(\sprintf('No user has the email "%s".', $email));
    }
}
