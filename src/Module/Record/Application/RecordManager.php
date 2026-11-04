<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ManyToManyOnDelete;
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
 */
final class RecordManager implements RecordManagerInterface, RecordFinderInterface
{
    public const string CONFLICT_MESSAGE = 'This record conflicts with an existing one, please try again.';

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

        return $this->store->find($object, $id) ?? throw new \LogicException('The record just created cannot be read back.');
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

        return $this->store->find($object, $id) ?? throw RecordNotFound::withId($object->name, $id);
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
        // The relations pointing at it, then the many-to-many fields linking to it: both read
        // the same way, through the store.
        foreach ([...$this->metadata->incomingRelations($objectName), ...$this->metadata->incomingManyToMany($objectName)] as $reference) {
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
        return Uuid::isValid($id) ? $this->store->find($this->metadata->getObject($objectName), $id) : null;
    }

    public function findReferencing(string $objectName, string $fieldName, string $targetId): array
    {
        $object = $this->metadata->getObject($objectName);
        $records = [];
        // The store lists the newest first.
        foreach (array_reverse($this->store->referencingIds($object, $object->field($fieldName), $targetId)) as $id) {
            $record = $this->store->find($object, $id);
            if (null !== $record) {
                $records[] = $record;
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
            if (null !== $field->manyToMany()) {
                $ids = \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
                $this->store->replaceLinks($object, $field, $id, $ids, $now);
            }
        }
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
        $links = $this->metadata->incomingManyToMany($object->name);
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
