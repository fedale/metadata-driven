<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ServiceFieldDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\CompositeRecordManagerInterface;
use App\Module\Record\Contract\Dto\CompositeNodeInput;
use App\Module\Record\Contract\Dto\CompositeRecord;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\CompositeValidationFailed;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Shared\Transaction\TransactionRunner;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Writes a composite form in two passes inside one transaction: the whole input is planned
 * and checked first (so every error is reported at once), then written top-down, each
 * section's records pointing at the record of their parent node through the "via" field.
 *
 * @phpstan-type Plan array{node: FormNodeDto, object: ObjectDefinitionDto, id: string|null, values: array<string, mixed>, path: string, sections: list<SectionPlan>}
 * @phpstan-type SectionPlan array{section: FormNodeDto, path: string, rows: list<mixed>, deletes: list<string>}
 */
final class CompositeRecordManager implements CompositeRecordManagerInterface
{
    public const string NOT_IN_FORM_MESSAGE = 'This field is not part of the form.';
    public const string UNKNOWN_ROW_MESSAGE = 'This row does not belong to the record.';
    public const string CHECKED_MESSAGE = 'This box must be checked.';
    // The same message as a required field of a record.
    public const string REQUIRED_MESSAGE = 'This value is required.';

    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordManagerInterface $records,
        private readonly RecordFinderInterface $finder,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly ValidatorInterface $validator,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function save(string $formName, ?string $rootId, CompositeNodeInput $input): string
    {
        $root = $this->metadata->getForm($formName)->root;

        return $this->transactions->run(function () use ($root, $rootId, $input): string {
            if (null !== $rootId && null === $this->finder->find($root->object, $rootId)) {
                throw RecordNotFound::withId($root->object, $rootId);
            }
            $errors = [];
            $plan = $this->plan($root, $input, $rootId, '', $errors);
            if ([] !== $errors) {
                throw new CompositeValidationFailed($errors);
            }

            return $this->write($plan, null);
        });
    }

    public function read(string $formName, string $rootId): ?CompositeRecord
    {
        $root = $this->metadata->getForm($formName)->root;
        $record = $this->finder->find($root->object, $rootId);

        return null === $record ? null : $this->compose($root, $record);
    }

    private function compose(FormNodeDto $node, RecordOutput $record): CompositeRecord
    {
        $sections = [];
        foreach ($node->sections as $section) {
            $rows = array_map(
                fn (RecordOutput $row): CompositeRecord => $this->compose($section, $row),
                $this->finder->findReferencing($section->object, (string) $section->via, $record->id),
            );
            $sections[$section->name] = FormNodeMode::Many === $section->mode ? $rows : ($rows[0] ?? null);
        }

        return new CompositeRecord($record, $sections);
    }

    /**
     * @param array<string, list<string>> $errors collects the errors, keyed by path
     *
     * @return Plan
     */
    private function plan(FormNodeDto $node, CompositeNodeInput $input, ?string $id, string $path, array &$errors): array
    {
        $object = $this->metadata->getObject($node->object);
        $allowed = array_flip($node->fieldNames($object));
        $values = [];
        foreach ($input->values as $name => $value) {
            if (isset($allowed[$name])) {
                $values[$name] = $value;
            } else {
                $errors[$path.'values.'.$name][] = self::NOT_IN_FORM_MESSAGE;
            }
        }

        // The "via" field is set when writing, to the id of the parent record.
        $recordErrors = $this->records->check(new RecordInput($object->name, $values), $id);
        unset($recordErrors[(string) $node->via]);
        foreach ($recordErrors as $field => $messages) {
            $errors['' === $field ? rtrim($path, '.') : $path.'values.'.$field] = $messages;
        }
        foreach ($node->required as $field) {
            $message = $this->checkRequiredHere($object, $field, $values[$field] ?? null, null === $id, $values);
            if (null !== $message && !isset($recordErrors[$field])) {
                $errors[$path.'values.'.$field] = [$message];
            }
        }
        foreach ($node->serviceFields as $field) {
            $messages = $this->checkServiceField($field, $input->service[$field->name] ?? null);
            if ([] !== $messages) {
                $errors[$path.'service.'.$field->name] = $messages;
            }
        }

        $sections = [];
        foreach ($node->sections as $section) {
            $sections[] = $this->planSection($section, $input->sections[$section->name] ?? null, $id, $path.'sections.'.$section->name, $errors);
        }

        return ['node' => $node, 'object' => $object, 'id' => $id, 'values' => $values, 'path' => $path, 'sections' => $sections];
    }

    /**
     * @param CompositeNodeInput|array<int|string, CompositeNodeInput>|null $input
     * @param array<string, list<string>>                                   $errors
     *
     * @return SectionPlan
     */
    private function planSection(FormNodeDto $section, CompositeNodeInput|array|null $input, ?string $parentId, string $path, array &$errors): array
    {
        $existing = null === $parentId ? [] : array_map(
            static fn (RecordOutput $record): string => $record->id,
            $this->finder->findReferencing($section->object, (string) $section->via, $parentId),
        );

        if (FormNodeMode::One === $section->mode) {
            $rows = [];
            $row = $input instanceof CompositeNodeInput ? $input : null;
            if (null !== $row && ([] !== $existing || !$this->isEmpty($section, $row))) {
                $rows[] = $this->plan($section, $row, $existing[0] ?? null, $path.'.', $errors);
            }

            return ['section' => $section, 'path' => $path, 'rows' => $rows, 'deletes' => []];
        }

        $rows = [];
        $kept = [];
        foreach (\is_array($input) ? $input : [] as $key => $row) {
            $rowPath = $path.'.'.$key.'.';
            if (null !== $row->id) {
                if (!\in_array($row->id, $existing, true)) {
                    $errors[rtrim($rowPath, '.')][] = self::UNKNOWN_ROW_MESSAGE;
                    continue;
                }
                $kept[] = $row->id;
            } elseif ($this->isEmpty($section, $row)) {
                continue;
            }
            $rows[] = $this->plan($section, $row, $row->id, $rowPath, $errors);
        }

        if (\count($rows) < $section->min) {
            $errors[$path][] = 1 === $section->min ? 'Add at least one row.' : \sprintf('Add at least %d rows.', $section->min);
        }
        if (null !== $section->max && \count($rows) > $section->max) {
            $errors[$path][] = \sprintf('At most %d rows.', $section->max);
        }

        return ['section' => $section, 'path' => $path, 'rows' => $rows, 'deletes' => array_values(array_diff($existing, $kept))];
    }

    /**
     * @param Plan $plan
     */
    private function write(array $plan, ?string $parentId): string
    {
        $values = $plan['values'];
        if (null !== $plan['node']->via) {
            $values[$plan['node']->via] = $parentId;
        }
        $input = new RecordInput($plan['object']->name, $values);
        try {
            $id = null === $plan['id'] ? $this->records->create($input)->id : $this->records->update($plan['id'], $input)->id;
        } catch (RecordValidationFailed $e) {
            // A conflict between two rows of the same submission, or a concurrent write.
            $errors = [];
            foreach ($e->errors as $field => $messages) {
                $errors['' === $field ? rtrim($plan['path'], '.') : $plan['path'].'values.'.$field] = $messages;
            }
            throw new CompositeValidationFailed($errors);
        }

        foreach ($plan['sections'] as $section) {
            // Deletions first: a row added again may reuse the unique values of a removed one.
            foreach ($section['deletes'] as $deleted) {
                try {
                    $this->records->delete($section['section']->object, $deleted);
                } catch (RecordDeleteRestricted $e) {
                    throw new CompositeValidationFailed([$section['path'] => [$e->getMessage()]]);
                }
            }
            /** @var list<Plan> $rows the plans of the section's records */
            $rows = $section['rows'];
            foreach ($rows as $row) {
                $this->write($row, $id);
            }
        }

        return $id;
    }

    /**
     * A node nobody filled in: no id, every value empty or left at its default, and nothing
     * in its sections. Such rows are skipped instead of being refused as incomplete.
     */
    private function isEmpty(FormNodeDto $node, CompositeNodeInput $input): bool
    {
        if (null !== $input->id) {
            return false;
        }
        $object = $this->metadata->getObject($node->object);
        foreach ($input->values as $name => $value) {
            $default = $object->hasField($name) ? $this->fieldTypes->get($object->field($name)->type)->defaultValue($object->field($name)->settings) : null;
            if (!self::isBlank($value) && !self::sameScalar($value, $default)) {
                return false;
            }
        }
        foreach ($input->service as $value) {
            if (!self::isBlank($value)) {
                return false;
            }
        }
        foreach ($node->sections as $section) {
            $sectionInput = $input->sections[$section->name] ?? null;
            $rows = $sectionInput instanceof CompositeNodeInput ? [$sectionInput] : ($sectionInput ?? []);
            foreach ($rows as $row) {
                if (!$this->isEmpty($section, $row)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A field the form requires although its object does not: given, or a checked box. An
     * existing password left empty is kept, so it counts as given; a field whose condition is
     * not met is not required.
     */
    /**
     * @param array<string, mixed> $values the node's values, for the field's condition
     */
    private function checkRequiredHere(ObjectDefinitionDto $object, string $field, mixed $value, bool $isNew, array $values): ?string
    {
        $definition = $object->field($field);
        $type = $this->fieldTypes->get($definition->type);
        if ((!$isNew && $type->isWriteOnly()) || !$definition->appliesTo($values)) {
            return null;
        }
        if (false === $value) {
            return self::CHECKED_MESSAGE;
        }

        return self::isBlank($value) ? self::REQUIRED_MESSAGE : null;
    }

    /**
     * @return list<string>
     */
    private function checkServiceField(ServiceFieldDto $field, mixed $raw): array
    {
        $type = $this->fieldTypes->get($field->type);
        try {
            $value = $type->denormalize($raw, $field->settings);
        } catch (InvalidFieldValue $e) {
            return [$e->getMessage()];
        }
        if (null === $value || false === $value) {
            if (!$field->isRequired) {
                return [];
            }

            return [false === $value ? self::CHECKED_MESSAGE : self::REQUIRED_MESSAGE];
        }

        $messages = [];
        foreach ($this->validator->validate($value, $type->constraints($field->settings)) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    private static function isBlank(mixed $value): bool
    {
        return null === $value || false === $value || [] === $value || (\is_string($value) && '' === trim($value));
    }

    private static function sameScalar(mixed $value, mixed $default): bool
    {
        if (null === $default) {
            return false;
        }

        return \is_scalar($value) && \is_scalar($default) && (string) $value === (string) $default;
    }
}
