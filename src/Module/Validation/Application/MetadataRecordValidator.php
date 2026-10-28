<?php

declare(strict_types=1);

namespace App\Module\Validation\Application;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Validation\Contract\RecordValidatorInterface;
use App\Module\Validation\Contract\ReferenceCheckerInterface;
use App\Module\Validation\Contract\UniqueValueCheckerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Builds a Symfony Collection constraint from the field definitions at runtime.
 */
final class MetadataRecordValidator implements RecordValidatorInterface
{
    public const string REQUIRED_MESSAGE = 'This value is required.';
    public const string UNIQUE_MESSAGE = 'This value is already used.';
    public const string REFERENCE_MESSAGE = 'The selected record does not exist.';
    public const string PARENT_MISSING_MESSAGE = 'Choose the %s first.';
    public const string PARENT_MISMATCH_MESSAGE = 'The selected record does not belong to the chosen %s.';

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly UniqueValueCheckerInterface $uniqueValues,
        private readonly ReferenceCheckerInterface $references,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    public function validate(ObjectDefinitionDto $object, array $values, ?string $recordId = null): array
    {
        $errors = [];
        foreach ($this->validator->validate($values, $this->buildConstraint($object, $values)) as $violation) {
            $errors[trim($violation->getPropertyPath(), '[]')][] = (string) $violation->getMessage();
        }

        foreach ($object->fields as $name => $field) {
            $value = $values[$name] ?? null;
            if (isset($errors[$name]) || null === $value) {
                continue;
            }
            if ($field->isUnique && $this->uniqueValues->isTaken($object, $field, $value, $recordId)) {
                $errors[$name][] = self::UNIQUE_MESSAGE;
            }
            $relation = $field->relation();
            if (null !== $relation && \is_string($value)
                && !$this->references->exists($this->metadata->getObject($relation->target), $value)) {
                $errors[$name][] = self::REFERENCE_MESSAGE;
                continue;
            }
            // A cascade: the municipality must belong to the province chosen in the same record.
            if (null !== $relation?->dependsOn && null !== $relation->dependsVia && \is_string($value) && $object->hasField($relation->dependsOn)) {
                $parentLabel = mb_strtolower($object->field($relation->dependsOn)->label);
                $parent = $values[$relation->dependsOn] ?? null;
                if (!\is_string($parent)) {
                    $errors[$name][] = \sprintf(self::PARENT_MISSING_MESSAGE, $parentLabel);
                } elseif (!$this->references->pointsAt($this->metadata->getObject($relation->target), $value, $relation->dependsVia, $parent)) {
                    $errors[$name][] = \sprintf(self::PARENT_MISMATCH_MESSAGE, $parentLabel);
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $values the deciding fields of the conditions among them
     */
    private function buildConstraint(ObjectDefinitionDto $object, array $values): Assert\Collection
    {
        $fields = [];
        foreach ($object->fields as $name => $field) {
            // Type constraints ignore null; "required" is the only rule about missing values.
            $constraints = $this->fieldTypes->get($field->type)->constraints($field->settings);
            // A field whose condition is not met is not required (and has been emptied).
            if ($field->isRequired && $field->appliesTo($values)) {
                array_unshift($constraints, new Assert\NotNull(message: self::REQUIRED_MESSAGE));
            }
            // Optional: an update only carries the fields that change.
            $fields[$name] = new Assert\Optional($constraints);
        }

        return new Assert\Collection(fields: $fields, allowExtraFields: false);
    }
}
