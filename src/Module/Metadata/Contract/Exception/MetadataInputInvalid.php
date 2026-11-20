<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * The input of a metadata change breaks a rule; the errors are keyed by input name
 * (e.g. "name", "labelPlural") so that a form can show them next to its fields.
 *
 * An error is a Message, or a plain string when it comes already translated (a validator's).
 */
final class MetadataInputInvalid extends MetadataException
{
    /**
     * @param array<string, list<string|Message>> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(new MessageList(array_map(
            static fn (string $input, array $messages): Message => new Message('metadata.input_invalid', '%input%: %errors%', ['input' => $input, 'errors' => new MessageList($messages, ' ')]),
            array_keys($errors),
            $errors,
        ), ' '));
    }
}
