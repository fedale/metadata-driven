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
     * Tables adopted together: a foreign key from one to another (to its primary key) is
     * proposed as a relation to the object the other becomes.
     *
     * @param list<string> $tables
     *
     * @return list<AdoptionProposal> the tables pointed at first (as far as they do not point
     *                                at each other in a circle)
     *
     * @throws AdoptionRefused one of them is not one that can be adopted
     */
    public function proposeSet(array $tables): array;

    /**
     * The tables no object owns, that can be adopted, linked to one of $tables by a foreign key
     * (either way), and not among them.
     *
     * @param list<string> $tables
     *
     * @return array{pointedAt: list<string>, pointing: list<string>} the ones they point at, the ones pointing at them
     */
    public function related(array $tables): array;

    /**
     * @param bool $dryRun everything is done, then rolled back: the report tells what would be
     *
     * @throws AdoptionRefused nothing was changed
     */
    public function adopt(AdoptionRequest $request, bool $dryRun = false): AdoptionReport;

    /**
     * Several tables at once, in one transaction: all of them, or none.
     *
     * @param list<AdoptionRequest> $requests
     *
     * @return list<AdoptionReport> in the order of $requests
     *
     * @throws AdoptionRefused nothing was changed
     */
    public function adoptSet(array $requests, bool $dryRun = false): array;
}
