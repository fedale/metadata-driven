<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordInput;
use Symfony\Component\Uid\Uuid;

/**
 * Converts the raw values of a RecordInput to typed PHP values, field by field.
 */
final class RecordInputDenormalizer
{
    public const string UNKNOWN_FIELD_MESSAGE = 'This field does not exist.';
    public const string ROWS_MESSAGE = 'This value should be a list of rows.';
    public const string ROW_ID_MESSAGE = 'This row has no valid id.';

    public function __construct(
        private readonly FieldTypeRegistryInterface $fieldTypes,
    ) {
    }

    /**
     * @param bool $complete true on create: fields that were not provided take their default value, or null
     *
     * @return array{values: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function denormalize(ObjectDefinitionDto $object, RecordInput $input, bool $complete): array
    {
        $values = [];
        $errors = [];

        foreach (array_keys(array_diff_key($input->values, $object->fields)) as $unknown) {
            $errors[(string) $unknown][] = self::UNKNOWN_FIELD_MESSAGE;
        }

        foreach ($object->fields as $name => $field) {
            // Rows of a junction object: only their shape here; each one is checked as a record
            // of the junction by the record manager.
            if (true === $field->manyToMany()?->goesThrough()) {
                if ($input->has($name)) {
                    $rows = self::rows($input->values[$name], $name, $errors);
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
                $value = $type->denormalize($input->values[$name], $field->settings);
            } catch (InvalidFieldValue $e) {
                $errors[$name][] = $e->getMessage();
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
     * @param array<string, list<string>> $errors
     *
     * @return array<int|string, array<string, mixed>>|null
     */
    private static function rows(mixed $raw, string $name, array &$errors): ?array
    {
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_array($raw)) {
            $errors[$name][] = self::ROWS_MESSAGE;

            return null;
        }
        $rows = [];
        foreach ($raw as $key => $row) {
            if (!\is_array($row) || ([] !== $row && array_is_list($row))) {
                $errors[$name][] = self::ROWS_MESSAGE;

                return null;
            }
            /** @var array<string, mixed> $row a map: its keys are strings */
            $id = $row['id'] ?? null;
            if (null !== $id && '' !== $id && (!\is_string($id) || !Uuid::isValid($id))) {
                $errors[$name.'.'.$key][] = self::ROW_ID_MESSAGE;
                continue;
            }
            $row['id'] = \is_string($id) && '' !== $id ? strtolower($id) : null;
            $rows[$key] = $row;
        }

        return $rows;
    }
}
