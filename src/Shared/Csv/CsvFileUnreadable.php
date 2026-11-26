<?php

declare(strict_types=1);

namespace App\Shared\Csv;

use App\Shared\Message\Message;

/**
 * A CSV file that cannot be read as one: no header, a header twice, a line that does not fit,
 * not UTF-8. The message is the English text; $text is the same for a page, in the user's
 * language (see MessageTranslator).
 */
final class CsvFileUnreadable extends \UnexpectedValueException
{
    public function __construct(
        public readonly Message $text,
    ) {
        parent::__construct((string) $text);
    }
}
