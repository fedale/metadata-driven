<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Metadata\Contract\Exception\FormDefinitionNotFound;
use App\Module\Record\Contract\Dto\CompositeNodeInput;
use App\Module\Record\Contract\Dto\CompositeRecord;
use App\Module\Record\Contract\Exception\CompositeValidationFailed;
use App\Module\Record\Contract\Exception\RecordNotFound;

/**
 * Reads and writes the records of a composite form: a root record and, through the form's
 * sections, the records related to it.
 */
interface CompositeRecordManagerInterface
{
    /**
     * Validates the whole input first (every record, the service fields, the row counts), then
     * writes it in one transaction: all or nothing. Rows left empty are skipped.
     *
     * @param string|null $rootId null to create the root record
     *
     * @return string the id of the root record
     *
     * @throws FormDefinitionNotFound
     * @throws RecordNotFound            the root record (or a row's record) does not exist
     * @throws CompositeValidationFailed
     */
    public function save(string $formName, ?string $rootId, CompositeNodeInput $input): string;

    /**
     * @throws FormDefinitionNotFound
     */
    public function read(string $formName, string $rootId): ?CompositeRecord;
}
