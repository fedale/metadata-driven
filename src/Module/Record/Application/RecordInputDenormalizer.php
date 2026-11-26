<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Shared\Message\Message;

/**
 * Converts the raw values of a RecordInput to typed PHP values, field by field.
 */
final class RecordInputDenormalizer
{
    public function __construct(
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * @param bool $complete true on create: fields that were not provided take their default value, or null
     *
     * @return array{values: array<string, mixed>, errors: array<string, list<string|Message>>}
     */
    public function denormalize(ObjectDefinitionDto $object, RecordInput $input, bool $complete): array
    {
        $values = [];
        $errors = [];

        foreach (array_keys(array_diff_key($input->values, $object->fields)) as $unknown) {
            $errors[(string) $unknown][] = new Message('record.value.unknown_field', 'This field does not exist.');
        }

        foreach ($object->fields as $name => $field) {
            // Rows of a junction object: only their shape here; each one is checked as a record
            // of the junction by the record manager.
            if (true === $field->manyToMany()?->goesThrough()) {
                if ($input->has($name)) {
                    $rows = self::rows($input->values[$name], $name, $this->keyType((string) $field->manyToMany()->through), $errors);
                    if (null !== $rows) {
                        $values[$name] = [] === $rows ? null : $rows;
                    }
                } elseif ($complete) {
                    // So that a required field without rows is reported.
                    $values[$name] = null;
                }
                continue;
            }
            $type = $this->fieldTypes->get($field->type);
            if (!$input->has($name)) {
                if ($complete) {
                    $values[$name] = $type->defaultValue($field->settings);
                }
                continue;
            }

            try {
                $value = $this->keys($field, $type->denormalize($input->values[$name], $field->settings));
            } catch (InvalidFieldValue $e) {
                $errors[$name][] = $e->text;
                continue;
            }
            // An empty write-only field (a password left blank) keeps the stored value.
            if (null === $value && !$complete && $type->isWriteOnly()) {
                continue;
            }
            $values[$name] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The rows of a field going through a junction object, keys kept: a map each, with a valid
     * "id" or none. Null (and errors) when the shape is wrong.
     *
     * @param array<string, list<string|Message>> $errors
     *
     * @return array<int|string, array<string, mixed>>|null
     */
    private static function rows(mixed $raw, string $name, KeyType $keyType, array &$errors): ?array
    {
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_array($raw)) {
            $errors[$name][] = new Message('record.value.not_rows', 'This value should be a list of rows.');

            return null;
        }
        $rows = [];
        foreach ($raw as $key => $row) {
            if (!\is_array($row) || ([] !== $row && array_is_list($row))) {
                $errors[$name][] = new Message('record.value.not_rows', 'This value should be a list of rows.');

                return null;
            }
            /** @var array<string, mixed> $row a map: its keys are strings */
            $id = $row['id'] ?? null;
            $row['id'] = null === $id || '' === $id ? null : $keyType->normalize($id);
            if (null !== $id && '' !== $id && null === $row['id']) {
                $errors[$name.'.'.$key][] = new Message('record.value.row_without_id', 'This row has no valid id.');
                continue;
            }
            $rows[$key] = $row;
        }

        return $rows;
    }

    /**
     * The ids a relation or many-to-many field holds, in the canonical text of its target's key
     * (the field type takes any text: it does not know the target); any other value as it is.
     *
     * @throws InvalidFieldValue
     */
    private function keys(FieldDefinitionDto $field, mixed $value): mixed
    {
        $target = $field->relation()->target ?? $field->manyToMany()?->target;
        if (null === $target || null === $value) {
            return $value;
        }
        $keyType = $this->keyType($target);
        $normalize = static fn (mixed $id): string => $keyType->normalize($id) ?? throw new InvalidFieldValue(new Message('field_type.value.not_reference', 'This value is not a valid record reference.'));

        return \is_array($value) ? array_values(array_unique(array_map($normalize, $value))) : $normalize($value);
    }

    private function keyType(string $objectName): KeyType
    {
        return $this->metadata->hasObject($objectName) ? $this->metadata->getObject($objectName)->keyType : KeyType::Uuid;
    }
}
