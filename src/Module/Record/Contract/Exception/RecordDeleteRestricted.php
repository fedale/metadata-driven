<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Exception;

use App\Shared\Message\Message;

/**
 * The record cannot be deleted: a "restrict" relation still points at it, or a workflow guarding
 * the delete refused it.
 *
 * getMessage() is the English text; text() the same for a page, in the user's language (see
 * MessageTranslator). A refuse step's own message is a plain string, shown as it was written.
 */
final class RecordDeleteRestricted extends \DomainException
{
    private function __construct(
        private readonly string|Message $text,
    ) {
        parent::__construct((string) $text);
    }

    public static function referencedBy(int $count, string $referencingLabel): self
    {
        return new self(new Message('record.delete_restricted', 'This record cannot be deleted: %count% %records% still refer to it.', ['count' => $count, 'records' => $referencingLabel]));
    }

    /**
     * Refused by a listener of RecordWriting (a workflow guarding the delete), with its message.
     */
    public static function refused(string|Message $message): self
    {
        return new self($message);
    }

    public function text(): string|Message
    {
        return $this->text;
    }
}
