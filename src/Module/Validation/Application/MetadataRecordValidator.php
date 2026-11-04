<?php

declare(strict_types=1);

namespace App\Module\Validation\Application;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Validation\Contract\RecordValidatorInterface;
use App\Module\Validation\Contract\ReferenceCheckerInterface;
use App\Module\Validation\Contract\UniqueValueCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Builds a Symfony Collection constraint from the field definitions at runtime.
 */
final class MetadataRecordValidator implements RecordValidatorInterface
{
    public const string REQUIRED_MESSAGE = 'This value is required.';
    public const string UNIQUE_MESSAGE = 'This value is already used.';
    public const string UNIQUE_TOGETHER_MESSAGE = 'Another record already has the same %s.';
    public const string REFERENCE_MESSAGE = 'The selected record does not exist.';
    public const string REFERENCES_MESSAGE = 'One of the selected records does not exist.';
    public const string PARENT_MISSING_MESSAGE = 'Choose the %s first.';
    public const string PARENT_MISMATCH_MESSAGE = 'The selected record does not belong to the chosen %s.';

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly UniqueValueCheckerInterface $uniqueValues,
        private readonly ReferenceCheckerInterface $references,
        // The labels in the user's language.
        #[Target('localizedMetadata')]
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
            $manyToMany = $field->manyToMany();
            if (null !== $manyToMany && \is_array($value)
                && [] !== $this->references->missing($this->metadata->getObject($manyToMany->target), array_values(array_filter($value, \is_string(...))))) {
                $errors[$name][] = self::REFERENCES_MESSAGE;
                continue;
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

        return $this->checkUniqueTogether($object, $values, $recordId, $errors);
    }

    /**
     * Each set unique together that the write touches is checked once; its error goes to the
     * first of its fields the write carries. A set with a field already in error is skipped.
     *
     * @param array<string, mixed>        $values
     * @param array<string, list<string>> $errors
     *
     * @return array<string, list<string>>
     */
    private function checkUniqueTogether(ObjectDefinitionDto $object, array $values, ?string $recordId, array $errors): array
    {
        foreach ($object->uniqueTogether as $set) {
            $carried = array_values(array_filter($set, static fn (string $name): bool => \array_key_exists($name, $values)));
            if ([] === $carried || [] !== array_intersect($set, array_keys($errors))) {
                continue;
            }
            if ($this->uniqueValues->isTakenTogether($object, $set, $values, $recordId)) {
                $labels = array_map(static fn (string $name): string => mb_strtolower($object->field($name)->label), $set);
                $last = array_pop($labels);
                $errors[$carried[0]][] = \sprintf(self::UNIQUE_TOGETHER_MESSAGE, implode(', ', $labels).' and '.$last);
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
