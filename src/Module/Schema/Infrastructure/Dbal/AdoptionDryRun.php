<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Dbal;

use App\Module\Schema\Contract\Dto\AdoptionReport;

/**
 * Thrown at the end of a dry run, to roll the adoption back with its report.
 */
final class AdoptionDryRun extends \RuntimeException
{
    /**
     * @param list<AdoptionReport> $reports
     */
    public function __construct(public readonly array $reports)
    {
        parent::__construct('Dry run: rolled back.');
    }
}
