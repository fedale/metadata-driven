<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * An email that could not be written or queued: an address that is not one, the queue gone.
 */
final class MailNotSent extends \RuntimeException
{
    public static function to(string $address, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The email to "%s" could not be sent: %s', $address, null === $previous ? 'not an email address.' : rtrim($previous->getMessage(), '.').'.'), 0, $previous);
    }
}
