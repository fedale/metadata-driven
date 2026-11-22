<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract;

use App\Module\Schema\Contract\Dto\AdoptionProposal;
use App\Module\Schema\Contract\Dto\AdoptionReport;
use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Exception\AdoptionRefused;

/**
 * Turns a table loaded into the data schema by hand into an object: the metadata is built from
 * the table, and the table brought to the shape of an object's (renamed c_<object>, its system
 * columns added, its key and columns as the fields want them), in one transaction. Its records
 * and their keys stay as they are.
 */
interface TableAdopterInterface
{
    /**
     * @throws AdoptionRefused the table is not one that can be adopted
     */
    public function propose(string $table): AdoptionProposal;

    /**
     * @param bool $dryRun everything is done, then rolled back: the report tells what would be
     *
     * @throws AdoptionRefused nothing was changed
     */
    public function adopt(AdoptionRequest $request, bool $dryRun = false): AdoptionReport;
}
