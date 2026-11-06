<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

/**
 * Thrown at the end of a dry run, from inside its transaction, to roll it back. It carries the
 * report out; it never leaves TemplateUpgrader.
 *
 * @internal
 */
final class UpgradeRolledBack extends \RuntimeException
{
    public function __construct(
        public readonly UpgradeReport $report,
    ) {
        parent::__construct('Dry run completed: rolled back.');
    }
}
