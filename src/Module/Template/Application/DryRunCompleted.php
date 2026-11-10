<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

/**
 * Thrown at the end of a dry run, from inside its transaction: throwing is what rolls every
 * change back. It carries the reports out, one per template; it never leaves TemplateInstaller.
 *
 * @internal
 */
final class DryRunCompleted extends \RuntimeException
{
    /**
     * @param non-empty-list<InstallReport> $reports
     */
    public function __construct(
        public readonly array $reports,
    ) {
        parent::__construct('Dry run completed: rolled back.');
    }
}
