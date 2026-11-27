<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use App\Shared\Message\Message;

/**
 * An answer to a manual workflow's form, or an input given to a called workflow, that will not
 * do. getMessage() is the English text; $text the same, to show in the user's language.
 */
final class InvalidAnswer extends \InvalidArgumentException
{
    public function __construct(
        public readonly Message $text,
    ) {
        parent::__construct((string) $text);
    }
}
