<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Exception;

use App\Shared\Message\Message;

/**
 * An import that writes nothing: every reason found, each naming the line of the file, or the
 * option, it comes from.
 */
final class ImportRefused extends \DomainException
{
    /**
     * @param list<Message> $reasons English in getMessage(), for the console and the logs
     */
    public function __construct(
        public readonly array $reasons,
    ) {
        parent::__construct(\sprintf('Nothing was imported: %s', implode('; ', $reasons)));
    }
}
