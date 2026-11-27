<?php

declare(strict_types=1);

namespace App\Module\Package\Application;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;
use Fedale\PackageFormat\InvalidPackage;
use Fedale\PackageFormat\Problem;
use Fedale\PackageFormat\Text;

/**
 * The problems the package format finds, as messages to show in the user's language.
 *
 * The format declares its texts in English, each with a key (Fedale\PackageFormat\Text); they
 * are translated here under "package_format.<key>" in translations/errors.<locale>.yaml, and
 * ErrorCatalogsTest reads the keys from the format's sources. A problem without a key (a schema
 * violation, which the JSON Schema validator writes) stays in English.
 */
final class ProblemMessages
{
    public static function of(Problem $problem): string|Message
    {
        $message = null === $problem->text ? $problem->message : self::text($problem->text);

        return match (true) {
            '' === $problem->path && '' === $problem->at => $message,
            '' === $problem->at => new Message('package.problem.document', '%path%: %message%', ['path' => $problem->path, 'message' => $message]),
            '' === $problem->path => new Message('package.problem.package', '%at%: %message%', ['at' => $problem->at, 'message' => $message]),
            default => new Message('package.located', '%path%: %at%: %message%', ['path' => $problem->path, 'at' => $problem->at, 'message' => $message]),
        };
    }

    /**
     * @param list<Problem> $problems
     *
     * @return list<string|Message>
     */
    public static function all(array $problems): array
    {
        return array_map(self::of(...), $problems);
    }

    /**
     * The problems of a package as one text, for a message that quotes them.
     */
    public static function list(InvalidPackage $e, string $separator = '; '): MessageList
    {
        return new MessageList(self::all($e->problems), $separator);
    }

    private static function text(Text $text): Message
    {
        return Message::restored('package_format.'.$text->key, $text->text, array_map(
            static fn (string|int|Text $value): string|int|Message => $value instanceof Text ? self::text($value) : $value,
            $text->parameters,
        ));
    }
}
