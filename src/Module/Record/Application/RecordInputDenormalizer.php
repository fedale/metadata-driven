<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordInput;

/**
 * Converts the raw values of a RecordInput to typed PHP values, field by field.
 */
final class RecordInputDenormalizer
{
    public const string UNKNOWN_FIELD_MESSAGE = 'This field does not exist.';
    public const string JUNCTION_MESSAGE = 'These links are records of "%s": add or remove them there.';

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
            // Read-only here: the junction object's records are its links, written as records.
            $through = $field->manyToMany()?->through;
            if (null !== $through) {
                if ($input->has($name)) {
                    $errors[$name][] = \sprintf(self::JUNCTION_MESSAGE, $through);
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
}
