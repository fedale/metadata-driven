<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Message\Message;
use App\Shared\Message\MessageJson;
use App\Shared\Message\MessageList;

/**
 * Why a workflow run failed, in English: from a RunLog (a message) or from its stored column
 * (the message as JSON).
 */
final class RunErrors
{
    public static function english(mixed $error): ?string
    {
        if ($error instanceof Message || $error instanceof MessageList) {
            return (string) $error;
        }
        if (!\is_string($error)) {
            return null;
        }
        $decoded = json_decode($error, true);

        return null === $decoded ? $error : (string) MessageJson::decode($decoded);
    }
}
