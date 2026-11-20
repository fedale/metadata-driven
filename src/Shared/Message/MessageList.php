<?php

declare(strict_types=1);

namespace App\Shared\Message;

/**
 * Several texts shown as one, e.g. the uses that keep an object from being archived, joined by
 * "; ". Each item is translated on its own; a plain string (a name, a text already translated,
 * such as a validator's) is shown as it is.
 */
final readonly class MessageList implements \Stringable
{
    /**
     * @param list<string|Message> $items
     */
    public function __construct(
        public array $items,
        public string $separator = '; ',
    ) {
    }

    public function __toString(): string
    {
        return implode($this->separator, array_map(strval(...), $this->items));
    }
}
