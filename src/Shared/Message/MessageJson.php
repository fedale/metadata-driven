<?php

declare(strict_types=1);

namespace App\Shared\Message;

/**
 * A message as JSON can hold it, and back: for a text stored now and shown later, in the
 * language of whoever reads it then (the reasons of a background import).
 *
 * A Message is {key, text, parameters}, a MessageList {items, separator}; a plain string stays a
 * string, so texts stored before messages were (English only) read back as they are.
 */
final class MessageJson
{
    /**
     * @return string|array<string, mixed>
     */
    public static function encode(string|Message|MessageList $message): string|array
    {
        return match (true) {
            \is_string($message) => $message,
            $message instanceof MessageList => ['items' => array_map(self::encode(...), $message->items), 'separator' => $message->separator],
            default => ['key' => $message->key, 'text' => $message->text, 'parameters' => array_map(
                static fn (string|int|float|Message|MessageList $value): string|int|float|array => \is_scalar($value) ? $value : self::encode($value),
                $message->parameters,
            )],
        };
    }

    /**
     * @return string|Message|MessageList|null null: nothing a message was encoded to
     */
    public static function decode(mixed $data): string|Message|MessageList|null
    {
        if (\is_string($data)) {
            return $data;
        }
        if (!\is_array($data)) {
            return null;
        }
        if (\is_array($data['items'] ?? null) && \is_string($data['separator'] ?? null)) {
            $items = [];
            foreach ($data['items'] as $item) {
                $item = self::decode($item);
                // A list holds strings and messages only (see MessageList).
                if (\is_string($item) || $item instanceof Message) {
                    $items[] = $item;
                }
            }

            return new MessageList($items, $data['separator']);
        }
        if (!\is_string($data['key'] ?? null) || !\is_string($data['text'] ?? null)) {
            return null;
        }
        $parameters = [];
        foreach (\is_array($data['parameters'] ?? null) ? $data['parameters'] : [] as $name => $value) {
            $value = \is_int($value) || \is_float($value) ? $value : self::decode($value);
            if (null !== $value) {
                $parameters[(string) $name] = $value;
            }
        }

        return Message::restored($data['key'], $data['text'], $parameters);
    }
}
