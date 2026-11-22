<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Exception;

use App\Shared\Message\Message;

/**
 * A table could not be adopted: nothing was changed.
 */
final class AdoptionRefused extends \RuntimeException
{
    /**
     * @param list<Message> $reasons
     */
    public function __construct(public readonly array $reasons, ?\Throwable $previous = null)
    {
        parent::__construct(implode("\n", array_map(strval(...), $reasons)), 0, $previous);
    }
}
