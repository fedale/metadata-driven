<?php

declare(strict_types=1);

namespace App\Module\Package\Contract\Exception;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * Base class for every error raised by the Package module; its message is safe to show to users.
 *
 * getMessage() is the English text, for the console and the logs; text() is the same message
 * for a page, to show in the user's language (see MessageTranslator).
 */
abstract class PackageException extends \DomainException
{
    private readonly string|Message|MessageList $text;

    public function __construct(string|Message|MessageList $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
        $this->text = $message;
    }

    public function text(): string|Message|MessageList
    {
        return $this->text;
    }
}
