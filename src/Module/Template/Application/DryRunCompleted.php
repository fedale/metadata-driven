<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

/**
 * Thrown at the end of a dry run, from inside its transaction: throwing is what rolls every
 * change back. It carries the report out; it never leaves TemplateInstaller.
 *
 * @internal
 */
final class DryRunCompleted extends \RuntimeException
{
    public function __construct(
        public readonly InstallReport $report,
    ) {
        parent::__construct('Dry run completed: rolled back.');
    }
}
