<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Upgrade;

use App\Shared\Message\Message;

/**
 * What an upgrade would do, computed without changing anything: the changes it would apply,
 * the conflicts to resolve first, and what refuses it outright.
 */
final readonly class UpgradePreview
{
    /**
     * Each change and refusal is a Message to read in the user's language, or a plain string: a
     * problem the package format's checks found, which is English only.
     *
     * @param list<string|Message> $changes   the changes no one disputes
     * @param list<ConflictView>   $conflicts
     * @param list<string|Message> $refusals  nothing can be upgraded while there is one
     */
    public function __construct(
        public string $name,
        public string $from,
        public string $to,
        public array $changes,
        public array $conflicts,
        public array $refusals,
    ) {
    }
}
