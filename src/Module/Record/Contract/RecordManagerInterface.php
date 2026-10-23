<?php

declare(strict_types=1);

namespace App\Module\Record\Contract;

use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\RecordValidationFailed;

/**
 * Write side of records: converts, validates and persists them.
 */
interface RecordManagerInterface
{
    /**
     * @throws ObjectDefinitionNotFound
     * @throws RecordValidationFailed
     */
    public function create(RecordInput $input): RecordOutput;

    /**
     * Changes only the fields present in the input.
     *
     * @throws ObjectDefinitionNotFound
     * @throws RecordNotFound
     * @throws RecordValidationFailed
     */
    public function update(string $id, RecordInput $input): RecordOutput;

    /**
     * The errors create() ($id null) or update() would report, without writing anything.
     *
     * @return array<string, list<string>> error messages keyed by field name; empty when valid
     *
     * @throws ObjectDefinitionNotFound
     */
    public function check(RecordInput $input, ?string $id = null): array;

    /**
     * Soft deletes the record, applying the delete rule of every relation pointing at it:
     * references are cleared (set_null), the records pointing at it are deleted in turn
     * (cascade), or the deletion is refused (restrict). All or nothing.
     *
     * @throws ObjectDefinitionNotFound
     * @throws RecordNotFound
     * @throws RecordDeleteRestricted
     */
    public function delete(string $objectName, string $id): void;
}
