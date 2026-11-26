<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Exception;

use App\Shared\Message\Message;

/**
 * Raised when a raw value cannot be converted to the field type; the message is shown to users.
 *
 * getMessage() is the English text; $text is the same for a page, in the user's language (see
 * MessageTranslator): it travels with the record's errors (RecordValidationFailed) to the form,
 * the import's reasons, a workflow's failure.
 */
final class InvalidFieldValue extends \InvalidArgumentException
{
    public function __construct(
        public readonly Message $text,
    ) {
        parent::__construct((string) $text);
    }
}
