<?php

declare(strict_types=1);

namespace App\Shared\Message;

/**
 * A Message in the language of the page (or the one asked for), from
 * translations/errors.<locale>.yaml, with its English text as the fallback; in Twig,
 * `{{ reason|message }}`. A plain string is shown as it is.
 */
interface MessageTranslator
{
    public const string DOMAIN = 'errors';

    public function trans(string|Message|MessageList $message, ?string $locale = null): string;

    /**
     * @param list<string|Message|MessageList> $messages
     *
     * @return list<string>
     */
    public function transAll(array $messages, ?string $locale = null): array;
}
