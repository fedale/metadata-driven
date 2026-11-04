<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ManyToManyOnDelete;
use App\Module\Metadata\Contract\Dto\ManyToManySettings;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\OnDelete;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordLabel;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Dto\ReferencingGroup;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordLookupInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Record\Domain\Exception\UniqueValueConflict;
use App\Module\Record\Domain\Repository\RecordStore;
use App\Module\Validation\Contract\RecordValidatorInterface;
use App\Shared\Transaction\TransactionRunner;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Pipeline of every record write: RecordInput → denormalization → conditions (the fields
 * that do not apply are emptied) → validation → conversion to the stored form (e.g. password
 * hashes) → storage: the columns, then the links of the many-to-many fields, in one
 * transaction.
 *
 * The rows of a many-to-many field going through a junction object are records of the
 * junction: each one is checked, then written, through this same pipeline, after the record
 * (a new one gives them its id). Their errors are keyed by field, row and junction field:
 * "tags.0.role".
 */
final class RecordManager implements RecordManagerInterface, RecordFinderInterface
{
    public const string CONFLICT_MESSAGE = 'This record conflicts with an existing one, please try again.';
    // The same message as an unknown row of a composite form.
    public const string UNKNOWN_ROW_MESSAGE = 'This row does not belong to the record.';
    public const string SET_BY_RECORD_MESSAGE = 'This field is set by the record.';
    public const string LINKED_TWICE_MESSAGE = 'This record is already linked.';

    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordInputDenormalizer $denormalizer,
        private readonly RecordValidatorInterface $validator,
        private readonly RecordStore $store,
        private readonly RecordLookupInterface $lookup,
        private readonly TransactionRunner $transactions,
        private readonly FieldTypeRegistryInterface $fieldTypes,
    ) {
    }

    public function create(RecordInput $input): RecordOutput
    {
        $object = $this->metadata->getObject($input->objectName);
        $values = $this->validValues($object, $input, null);

        $id = Uuid::v7()->toRfc4122();
        $stored = $this->stored($object, $values);
        $now = new \DateTimeImmutable();
        $this->write($object, $values, null, fn () => $this->transactions->run(function () use ($object, $id, $stored, $now): void {
            $this->store->insert($object, $id, self::columns($object, $stored), $now);
            $this->writeLinks($object, $id, $stored, $now);
        }));

        return $this->withRows($object, $this->store->find($object, $id) ?? throw new \LogicException('The record just created cannot be read back.'));
    }

    public function update(string $id, RecordInput $input): RecordOutput
    {
        $object = $this->metadata->getObject($input->objectName);
        if (null === $this->store->find($object, $id)) {
            throw RecordNotFound::withId($object->name, $id);
        }
        $values = $this->validValues($object, $input, $id);

        if ([] !== $values) {
            $stored = $this->stored($object, $values);
            $now = new \DateTimeImmutable();
            // Columns or links alone: the update marks the record as changed either way.
            $updated = $this->write($object, $values, $id, fn (): bool => $this->transactions->run(function () use ($object, $id, $stored, $now): bool {
                if (!$this->store->update($object, $id, self::columns($object, $stored), $now)) {
                    return false;
                }
                $this->writeLinks($object, $id, $stored, $now);

                return true;
            }));
            if (!$updated) {
                throw RecordNotFound::withId($object->name, $id);
            }
        }

        return $this->withRows($object, $this->store->find($object, $id) ?? throw RecordNotFound::withId($object->name, $id));
    }

    public function check(RecordInput $input, ?string $id = null): array
    {
        $object = $this->metadata->getObject($input->objectName);
        try {
            $this->validValues($object, $input, $id);
        } catch (RecordValidationFailed $e) {
            return $e->errors;
        }

        return [];
    }

    public function delete(string $objectName, string $id): void
    {
        $object = $this->metadata->getObject($objectName);
        $visited = [];
        $this->transactions->run(fn () => $this->deleteWithRules($object, $id, new \DateTimeImmutable(), $visited));
    }

    public function referencing(string $objectName, string $id, int $limit = 20): array
    {
        $this->metadata->getObject($objectName);
        $groups = [];
        $junctionSides = $this->junctionSides();
        // The relations pointing at it, then the many-to-many fields linking to it: both read
        // the same way, through the store.
        foreach ([...$this->metadata->incomingRelations($objectName), ...$this->metadata->incomingManyToMany($objectName)] as $reference) {
            // The records of a junction object are links: the rows of the field going through
            // it, and the list of its many-to-many inverse side, show them already.
            if (isset($junctionSides[$reference->object->name.'.'.$reference->field->name]) && null !== $reference->field->relation()) {
                continue;
            }
            $total = $this->store->countReferencing($reference->object, $reference->field, $id);
            if (0 === $total) {
                continue;
            }
            $ids = $this->store->referencingIds($reference->object, $reference->field, $id, $limit);
            $labels = $this->lookup->labels($reference->object->name, $ids);
            $records = array_map(static fn (string $recordId): RecordLabel => new RecordLabel($recordId, $labels[$recordId] ?? RecordLabels::fallback($recordId)), $ids);
            usort($records, static fn (RecordLabel $a, RecordLabel $b): int => strnatcasecmp($a->label, $b->label));

            $groups[] = new ReferencingGroup($reference->object->name, $reference->field->name, $reference->inverseLabel(), $records, $total);
        }

        return $groups;
    }

    public function find(string $objectName, string $id): ?RecordOutput
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $object = $this->metadata->getObject($objectName);
        $record = $this->store->find($object, $id);

        return null === $record ? null : $this->withRows($object, $record);
    }

    public function findReferencing(string $objectName, string $fieldName, string $targetId): array
    {
        $object = $this->metadata->getObject($objectName);
        $records = [];
        // The store lists the newest first.
        foreach (array_reverse($this->store->referencingIds($object, $object->field($fieldName), $targetId)) as $id) {
            $record = $this->store->find($object, $id);
            if (null !== $record) {
                // A composite form's rows: they carry their own junction rows, as their form edits them.
                $records[] = $this->withRows($object, $record);
            }
        }

        return $records;
    }

    public function latest(string $objectName, int $limit = 50): array
    {
        return $this->store->latest($this->metadata->getObject($objectName), $limit);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RecordValidationFailed
     */
    private function validValues(ObjectDefinitionDto $object, RecordInput $input, ?string $recordId): array
    {
        ['values' => $values, 'errors' => $errors] = $this->denormalizer->denormalize($object, $input, complete: null === $recordId);
        $values = $this->applyConditions($object, $values, $recordId);

        // Fields that could not be converted are already reported: validate the others only.
        $errors += $this->validator->validate($object, array_diff_key($values, $errors), $recordId);
        $errors += $this->checkRows($object, array_diff_key($values, $errors), $recordId);
        if ([] !== $errors) {
            throw new RecordValidationFailed($object->name, $errors);
        }

        return $values;
    }

    /**
     * Empties the fields whose condition is not met, so that a hidden field never keeps a value
     * (a course's hours once the qualification becomes a language). On update the deciding
     * fields the input leaves out are read from the record, and written back unchanged, so that
     * the validator sees them too; so are both sides of a cascade, when one of them is written.
     *
     * @param array<string, mixed> $values denormalized
     *
     * @return array<string, mixed>
     */
    private function applyConditions(ObjectDefinitionDto $object, array $values, ?string $recordId): array
    {
        // The fields the rules read: the deciding fields of the conditions, and both sides of a
        // cascade (region, province, municipality) as soon as one side is written.
        $deciding = [];
        foreach ($object->fields as $name => $field) {
            if (null !== $field->condition) {
                $deciding[$field->condition->field] = true;
            }
            $dependsOn = $field->relation()?->dependsOn;
            if (null !== $dependsOn && (\array_key_exists($name, $values) || \array_key_exists($dependsOn, $values))) {
                $deciding[$name] = true;
                $deciding[$dependsOn] = true;
            }
        }
        if ([] === $deciding) {
            return $values;
        }

        if (null !== $recordId && [] !== array_diff_key($deciding, $values)) {
            $current = $this->store->find($object, $recordId)->values ?? [];
            foreach (array_keys(array_diff_key($deciding, $values)) as $name) {
                $values[$name] = $current[$name] ?? null;
            }
        }
        foreach ($object->fields as $name => $field) {
            if (!$field->appliesTo($values)) {
                $values[$name] = null;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values valid values
     *
     * @return array<string, mixed> the values as the columns store them
     */
    private function stored(ObjectDefinitionDto $object, array $values): array
    {
        foreach ($values as $name => $value) {
            $field = $object->field($name);
            $values[$name] = $this->fieldTypes->get($field->type)->toStorage($value, $field->settings);
        }

        return $values;
    }

    /**
     * The values stored in columns: a many-to-many field has none.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function columns(ObjectDefinitionDto $object, array $values): array
    {
        return array_filter($values, static fn (string $name): bool => $object->field($name)->storesColumn(), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * Writes the links of the many-to-many fields among $values; a field left out keeps its
     * links, a null one loses them all.
     *
     * @param array<string, mixed> $values valid values
     */
    private function writeLinks(ObjectDefinitionDto $object, string $id, array $values, \DateTimeImmutable $now): void
    {
        foreach ($values as $name => $value) {
            $field = $object->field($name);
            $manyToMany = $field->manyToMany();
            if (null === $manyToMany) {
                continue;
            }
            if ($manyToMany->goesThrough()) {
                $this->writeRows($object, $name, $manyToMany, $id, \is_array($value) ? $value : []);
                continue;
            }
            $ids = \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
            $this->store->replaceLinks($object, $field, $id, $ids, $now);
        }
    }

    /**
     * Checks the rows of the fields going through a junction object, each one as a record of
     * the junction; the record itself is not there yet, so its own field is left out. The
     * unique set of the junction would refuse a target twice when the rows are written: the
     * error is given here instead, on the row.
     *
     * @param array<string, mixed> $values denormalized
     *
     * @return array<string, list<string>> keyed by field, row and junction field
     */
    private function checkRows(ObjectDefinitionDto $object, array $values, ?string $recordId): array
    {
        $errors = [];
        foreach ($values as $name => $rows) {
            $settings = $object->field($name)->manyToMany();
            if (null === $settings || !$settings->goesThrough() || !\is_array($rows)) {
                continue;
            }
            [$junction, $source, $target] = $this->junction($settings);
            $existing = null === $recordId ? [] : $this->store->referencingIds($junction, $junction->field($source), $recordId);
            $linked = [];
            foreach ($rows as $key => $row) {
                $path = $name.'.'.$key;
                if (!\is_array($row)) {
                    continue;
                }
                $rowId = \is_string($row['id'] ?? null) ? $row['id'] : null;
                $row = self::rowValues($row);
                if (null !== $rowId && !\in_array($rowId, $existing, true)) {
                    $errors[$path][] = self::UNKNOWN_ROW_MESSAGE;
                    continue;
                }
                if (\array_key_exists($source, $row)) {
                    $errors[$path.'.'.$source][] = self::SET_BY_RECORD_MESSAGE;
                    unset($row[$source]);
                }
                foreach ($this->check(new RecordInput($junction->name, $row), $rowId) as $field => $messages) {
                    if ($field !== $source) {
                        $errors[RecordValidationFailed::GLOBAL_ERRORS === $field ? $path : $path.'.'.$field] = $messages;
                    }
                }
                // A row updating its record without naming the target keeps the stored one.
                $targetId = \array_key_exists($target, $row) ? $row[$target] : (null === $rowId ? null : $this->store->find($junction, $rowId)?->get($target));
                if (\is_string($targetId) && '' !== $targetId) {
                    if (isset($linked[strtolower($targetId)])) {
                        $errors[$path.'.'.$target][] = self::LINKED_TWICE_MESSAGE;
                    }
                    $linked[strtolower($targetId)] = true;
                }
            }
        }

        return $errors;
    }

    /**
     * Writes the rows of a field going through a junction object: the records of the junction
     * missing from the rows are deleted first (a row added back may reuse the target of a
     * removed one), then each row updates its record or creates one pointing at the record.
     *
     * @param array<mixed> $rows checked by checkRows()
     *
     * @throws RecordValidationFailed a conflict the check could not see (a concurrent write), or
     *                                a junction record that cannot be deleted
     */
    private function writeRows(ObjectDefinitionDto $object, string $name, ManyToManySettings $settings, string $id, array $rows): void
    {
        [$junction, $source] = $this->junction($settings);
        $kept = [];
        foreach ($rows as $row) {
            if (\is_array($row) && \is_string($row['id'] ?? null)) {
                $kept[] = $row['id'];
            }
        }
        foreach (array_diff($this->store->referencingIds($junction, $junction->field($source), $id), $kept) as $removed) {
            try {
                $this->delete($junction->name, $removed);
            } catch (RecordDeleteRestricted $e) {
                throw new RecordValidationFailed($object->name, [$name => [$e->getMessage()]]);
            }
        }
        foreach ($rows as $key => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $rowId = \is_string($row['id'] ?? null) ? $row['id'] : null;
            $row = self::rowValues($row);
            unset($row[$source]);
            try {
                null === $rowId
                    ? $this->create(new RecordInput($junction->name, [$source => $id] + $row))
                    : $this->update($rowId, new RecordInput($junction->name, $row));
            } catch (RecordValidationFailed $e) {
                $errors = [];
                foreach ($e->errors as $field => $messages) {
                    $errors[RecordValidationFailed::GLOBAL_ERRORS === $field ? $name.'.'.$key : $name.'.'.$key.'.'.$field] = $messages;
                }
                throw new RecordValidationFailed($object->name, $errors);
            }
        }
    }

    /**
     * The values of a row: its junction fields, without its "id".
     *
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function rowValues(array $row): array
    {
        $values = [];
        foreach ($row as $key => $value) {
            if (\is_string($key) && 'id' !== $key) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * The rows of the fields going through a junction object, for a record read by itself.
     */
    private function withRows(ObjectDefinitionDto $object, RecordOutput $record): RecordOutput
    {
        $rows = [];
        foreach ($object->fields as $name => $field) {
            $settings = $field->manyToMany();
            if (null === $settings || !$settings->goesThrough()) {
                continue;
            }
            [$junction, $source] = $this->junction($settings);
            $rows[$name] = [];
            // The store lists the newest first.
            foreach (array_reverse($this->store->referencingIds($junction, $junction->field($source), $record->id)) as $rowId) {
                $row = $this->store->find($junction, $rowId);
                if (null !== $row) {
                    $rows[$name][] = $row;
                }
            }
        }

        return [] === $rows ? $record : $record->withRows($rows);
    }

    /**
     * The relation fields of junction objects that a many-to-many field goes through, as
     * "<junction>.<field>".
     *
     * @return array<string, true>
     */
    private function junctionSides(): array
    {
        $sides = [];
        foreach ($this->metadata->allObjects() as $object) {
            foreach ($object->fields as $field) {
                $settings = $field->manyToMany();
                if (null !== $settings && $settings->goesThrough()) {
                    $sides[$settings->through.'.'.$settings->throughSource] = true;
                    $sides[$settings->through.'.'.$settings->throughTarget] = true;
                }
            }
        }

        return $sides;
    }

    /**
     * @return array{ObjectDefinitionDto, string, string} the junction object, its field pointing at the record, its field pointing at the target
     */
    private function junction(ManyToManySettings $settings): array
    {
        return [$this->metadata->getObject((string) $settings->through), (string) $settings->throughSource, (string) $settings->throughTarget];
    }

    /**
     * Runs a write; a unique index violation that slipped past validation (concurrent
     * write) is reported on the offending fields, found by validating again.
     *
     * @template T
     *
     * @param array<string, mixed> $values the values as validated (not as stored), to validate them again
     * @param callable(): T        $write
     *
     * @return T
     */
    private function write(ObjectDefinitionDto $object, array $values, ?string $recordId, callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueValueConflict) {
            $errors = $this->validator->validate($object, $values, $recordId);

            throw new RecordValidationFailed($object->name, [] !== $errors ? $errors : [RecordValidationFailed::GLOBAL_ERRORS => [self::CONFLICT_MESSAGE]]);
        }
    }

    /**
     * Restrict rules are all checked before anything changes; cascades recurse, and
     * $visited stops cycles (a record pointing at itself, or two records at each other).
     *
     * @param array<string, true> $visited
     */
    private function deleteWithRules(ObjectDefinitionDto $object, string $id, \DateTimeImmutable $now, array &$visited): void
    {
        $key = $object->name.':'.$id;
        if (isset($visited[$key])) {
            return;
        }
        $visited[$key] = true;

        $references = $this->metadata->incomingRelations($object->name);
        // A many-to-many field going through a junction object has no rule of its own: the
        // relation fields of the junction object, among $references, carry them.
        $links = array_filter(
            $this->metadata->incomingManyToMany($object->name),
            static fn ($link): bool => !$link->settings->goesThrough(),
        );
        foreach ($references as $reference) {
            if (OnDelete::Restrict === $reference->settings->onDelete
                && 0 < $count = $this->store->countReferencing($reference->object, $reference->field, $id)) {
                throw RecordDeleteRestricted::referencedBy($count, $reference->inverseLabel());
            }
        }
        foreach ($links as $link) {
            if (ManyToManyOnDelete::Restrict === $link->settings->onDelete
                && 0 < $count = $this->store->countReferencing($link->object, $link->field, $id)) {
                throw RecordDeleteRestricted::referencedBy($count, $link->inverseLabel());
            }
        }

        if (!$this->store->softDelete($object, $id, $now)) {
            throw RecordNotFound::withId($object->name, $id);
        }

        foreach ($references as $reference) {
            match ($reference->settings->onDelete) {
                OnDelete::SetNull => $this->store->clearReferences($reference->object, $reference->field, $id, $now),
                OnDelete::Cascade => array_map(
                    fn (string $referencingId) => $this->deleteWithRules($reference->object, $referencingId, $now, $visited),
                    $this->store->referencingIds($reference->object, $reference->field, $id),
                ),
                OnDelete::Restrict => null,
            };
        }
        // No cascade here: deleting a tag never deletes the contacts it was linked to.
        foreach ($links as $link) {
            if (ManyToManyOnDelete::Unlink === $link->settings->onDelete) {
                $this->store->clearReferences($link->object, $link->field, $id, $now);
            }
        }
    }
}
