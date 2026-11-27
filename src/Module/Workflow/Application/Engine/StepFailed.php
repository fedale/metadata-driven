<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Shared\Message\Message;

/**
 * A step could not do what it says: a permission missing, a record refused, nothing to update.
 * It ends the run: before a write, the write is refused; after one, the run fails.
 *
 * getMessage() is the English text, for the logs; text() is the same message as the run's log
 * keeps it, to show in the reader's language (see MessageTranslator). A plain string is a text
 * from elsewhere, as it was written (a refuse step's own message, an HTTP client's error).
 */
final class StepFailed extends \RuntimeException
{
    private readonly string|Message $text;

    public function __construct(string|Message $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
        $this->text = $message;
    }

    public function text(): string|Message
    {
        return $this->text;
    }
}
