<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Dto\TransitionOption;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\Exception\TransitionRefused;

/**
 * The transitions of a record's Status fields: the only way their value changes.
 */
interface RecordTransitionsInterface
{
    /**
     * The transitions the current user may take on the record, from its states now.
     *
     * @return list<TransitionOption>
     */
    public function available(string $objectName, RecordOutput $record): array;

    /**
     * Takes a transition: from the record's state, by whoever may (record.<object>.update and one
     * of its roles), unless $asRole is given (a workflow's step, which checks its role itself).
     * The record is updated through the record manager: its events, its workflows, its rules.
     *
     * @throws TransitionRefused
     * @throws RecordNotFound
     * @throws RecordValidationFailed a before-workflow refused it
     */
    public function apply(string $objectName, string $recordId, string $field, string $transition, bool $asRole = false): RecordOutput;
}
