<?php

declare(strict_types=1);

namespace App\Shared\Message;

/**
 * A text for a user that can be read in their language: an error, the reason of a refusal, a
 * change an upgrade makes.
 *
 * The English text is declared where the message is raised, with its parameters as `%name%`
 * placeholders; the other languages translate it by key, in translations/errors.<locale>.yaml
 * (there is no English file, as for the field types). Casting to string gives the English text,
 * which is what an exception message, a log or the console show: a message raised outside a
 * request needs no translator.
 *
 * The key and the text are literal strings at every `new Message(...)`: ErrorCatalogsTest reads
 * them from the sources to check that every key is translated, with the same placeholders.
 */
final readonly class Message implements \Stringable
{
    /**
     * @param array<string, string|int|float|Message|MessageList> $parameters by placeholder name,
     *                                                                        without the percent signs
     */
    public function __construct(
        public string $key,
        public string $text,
        public array $parameters = [],
    ) {
    }

    public function __toString(): string
    {
        return strtr($this->text, self::placeholders(array_map(strval(...), $this->parameters)));
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, string> `%name%` => value
     */
    public static function placeholders(array $parameters): array
    {
        $placeholders = [];
        foreach ($parameters as $name => $value) {
            $placeholders['%'.$name.'%'] = $value;
        }

        return $placeholders;
    }
}
