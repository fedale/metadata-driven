<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Exception;

use App\Module\Metadata\Contract\Exception\MetadataException;

final class InvalidMetadataDefinition extends MetadataException
{
    public static function invalidName(string $name, int $maxLength): self
    {
        return new self(\sprintf(
            'Invalid name "%s": use 2 to %d lowercase letters, digits and single underscores, starting with a letter.',
            $name,
            $maxLength,
        ));
    }

    public static function emptyLabel(): self
    {
        return new self('Labels cannot be empty.');
    }

    public static function reservedFieldName(string $name): self
    {
        return new self(\sprintf('Field name "%s" is reserved for a system column.', $name));
    }

    public static function duplicateField(string $objectName, string $fieldName): self
    {
        return new self(\sprintf('Object "%s" already has a field named "%s".', $objectName, $fieldName));
    }

    public static function archivedFieldNameTaken(string $objectName, string $fieldName): self
    {
        return new self(\sprintf('Object "%s" has an archived field named "%s": restore it, or purge it to free the name.', $objectName, $fieldName));
    }

    public static function unknownArchivedField(string $objectName, string $fieldName): self
    {
        return new self(\sprintf('Object "%s" has no archived field "%s".', $objectName, $fieldName));
    }

    public static function archivedObjectNameTaken(string $name): self
    {
        return new self(\sprintf('An archived object is named "%s": restore it, or purge it to free the name.', $name));
    }

    public static function unknownArchivedObject(string $name): self
    {
        return new self(\sprintf('No archived object is named "%s".', $name));
    }

    public static function systemObject(string $name, string $change): self
    {
        return new self(\sprintf('Object "%s" belongs to the system: it cannot be %s.', $name, $change));
    }

    /**
     * @param list<string> $usages where the thing is used, one line each
     */
    public static function stillUsed(string $what, string $change, array $usages): self
    {
        return new self(\sprintf('%s cannot be %s, it is still used: %s. Remove these uses first.', $what, $change, implode('; ', $usages)));
    }

    /**
     * @param list<string> $problems
     */
    public static function cannotRestore(string $what, array $problems): self
    {
        return new self(\sprintf('%s cannot be restored: %s.', $what, implode('; ', $problems)));
    }

    public static function duplicateObject(string $name): self
    {
        return new self(\sprintf('An object named "%s" already exists.', $name));
    }

    /**
     * @param list<string> $available
     */
    public static function unknownFieldType(string $type, array $available): self
    {
        return new self(\sprintf('Unknown field type "%s". Available types: %s.', $type, implode(', ', $available)));
    }

    public static function unknownSearchField(string $objectName, string $fieldName): self
    {
        return new self(\sprintf('Object "%s" has no field "%s" to search.', $objectName, $fieldName));
    }

    /**
     * @param list<string> $available
     */
    public static function unknownSearchHandler(string $handler, array $available): self
    {
        return new self(\sprintf('Unknown search handler "%s". Available handlers: %s.', $handler, implode(', ', $available)));
    }

    public static function unknownLabelField(string $objectName, string $fieldName): self
    {
        return new self(\sprintf('Object "%s" has no field "%s" to use as label.', $objectName, $fieldName));
    }

    public static function unknownRelationTarget(string $target): self
    {
        return new self(\sprintf('The related object "%s" does not exist.', $target));
    }

    public static function inverseNameTaken(string $targetName, string $inverseName): self
    {
        return new self(\sprintf('Object "%s" already has a field or an inverse relation named "%s".', $targetName, $inverseName));
    }

    public static function unknownField(string $objectName, string $fieldName): self
    {
        return new self(\sprintf('Object "%s" has no field "%s".', $objectName, $fieldName));
    }

    public static function notUniqueType(string $fieldName, string $type): self
    {
        return new self(\sprintf('Field "%s" is of type "%s", which cannot be unique.', $fieldName, $type));
    }

    public static function writeOnlyField(string $fieldName, string $use): self
    {
        return new self(\sprintf('Field "%s" is write-only (its value is never read back): it cannot be %s.', $fieldName, $use));
    }

    public static function fixedSetting(string $fieldName, string $setting): self
    {
        return new self(\sprintf('Setting "%s" of field "%s" cannot change once the field exists: it shapes the column.', $setting, $fieldName));
    }

    public static function optionStillUsed(string $fieldName, string $value, string $reason): self
    {
        return new self(\sprintf('Option "%s" of field "%s" cannot be removed: %s.', $value, $fieldName, $reason));
    }

    public static function invalidDependency(string $fieldName, string $reason): self
    {
        return new self(\sprintf('Field "%s" cannot depend on that: %s.', $fieldName, $reason));
    }

    public static function invalidCondition(string $fieldName, string $reason): self
    {
        return new self(\sprintf('Field "%s" cannot get this condition: %s.', $fieldName, $reason));
    }

    public static function unknownView(string $objectName, string $kind, string $name): self
    {
        return new self(\sprintf('Object "%s" has no %s view "%s".', $objectName, $kind, $name));
    }

    public static function unknownViewField(string $objectName, string $viewName, string $fieldName): self
    {
        return new self(\sprintf('View "%s": object "%s" has no field "%s".', $viewName, $objectName, $fieldName));
    }

    public static function duplicateViewToken(string $viewName, string $fieldName): self
    {
        return new self(\sprintf('View "%s": the field "%s" is placed more than once.', $viewName, $fieldName));
    }

    public static function viewTokenInsideTag(string $viewName): self
    {
        return new self(\sprintf('View "%s": {field} tokens go in the text, not inside a tag or an attribute.', $viewName));
    }
}
