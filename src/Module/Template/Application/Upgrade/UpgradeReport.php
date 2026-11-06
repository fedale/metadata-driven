<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * What an upgrade did, or, with dryRun, would have done before it was rolled back.
 */
final readonly class UpgradeReport
{
    /**
     * @param list<string> $applied the changes applied, described
     * @param list<string> $kept    the conflicts where the instance's version stayed
     */
    public function __construct(
        public string $name,
        public string $from,
        public string $to,
        public bool $dryRun,
        public array $applied,
        public array $kept,
    ) {
    }
}
