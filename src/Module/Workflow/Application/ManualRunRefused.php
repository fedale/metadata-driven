<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Shared\Message\Message;

/**
 * A manual run that may not start: no such workflow, not the user's to run, a record they may
 * not read, or answers that will not do (errors by input name).
 */
final class ManualRunRefused extends \RuntimeException
{
    public const int NOT_FOUND = 1;
    public const int FORBIDDEN = 2;
    public const int INVALID = 3;

    /**
     * @param array<string, string|Message> $errors input name => why its answer will not do
     */
    public function __construct(
        private readonly string|Message $text,
        int $code,
        public readonly array $errors = [],
    ) {
        parent::__construct((string) $text, $code);
    }

    /**
     * The message, to show in the user's language (see MessageTranslator); getMessage() is the
     * English, for the console.
     */
    public function text(): string|Message
    {
        return $this->text;
    }
}
