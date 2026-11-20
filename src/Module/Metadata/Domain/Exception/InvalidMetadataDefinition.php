<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Exception;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * A metadata change breaking a rule of the definitions.
 *
 * Every message is a whole sentence with its own key, the reasons included: a reason is a
 * Message of its own, never English text, and a sentence that changes with the case (what
 * cannot be done to what) has one key per case, so that each language can agree its words.
 */
final class InvalidMetadataDefinition extends MetadataException
{
    public static function invalidName(string $name, int $maxLength): self
    {
        return new self(new Message('metadata.invalid_name', 'Invalid name "%name%": use 2 to %max_length% lowercase letters, digits and single underscores, starting with a letter.', ['name' => $name, 'max_length' => $maxLength]));
    }

    public static function emptyLabel(): self
    {
        return new self(new Message('metadata.empty_label', 'Labels cannot be empty.'));
    }

    public static function reservedFieldName(string $name): self
    {
        return new self(new Message('metadata.reserved_field_name', 'Field name "%name%" is reserved for a system column.', ['name' => $name]));
    }

    public static function duplicateField(string $objectName, string $fieldName): self
    {
        return new self(new Message('metadata.duplicate_field', 'Object "%object%" already has a field named "%field%".', ['object' => $objectName, 'field' => $fieldName]));
    }

    public static function archivedFieldNameTaken(string $objectName, string $fieldName): self
    {
        return new self(new Message('metadata.archived_field_name_taken', 'Object "%object%" has an archived field named "%field%": restore it, or purge it to free the name.', ['object' => $objectName, 'field' => $fieldName]));
    }

    public static function unknownArchivedField(string $objectName, string $fieldName): self
    {
        return new self(new Message('metadata.unknown_archived_field', 'Object "%object%" has no archived field "%field%".', ['object' => $objectName, 'field' => $fieldName]));
    }

    public static function archivedObjectNameTaken(string $name): self
    {
        return new self(new Message('metadata.archived_object_name_taken', 'An archived object is named "%name%": restore it, or purge it to free the name.', ['name' => $name]));
    }

    public static function unknownArchivedObject(string $name): self
    {
        return new self(new Message('metadata.unknown_archived_object', 'No archived object is named "%name%".', ['name' => $name]));
    }

    public static function systemObjectArchived(string $name): self
    {
        return new self(new Message('metadata.system_object.archive', 'Object "%object%" belongs to the system: it cannot be archived.', ['object' => $name]));
    }

    public static function systemObjectRenamed(string $name): self
    {
        return new self(new Message('metadata.system_object.rename', 'Object "%object%" belongs to the system: it cannot be renamed.', ['object' => $name]));
    }

    /**
     * @param list<string|Message> $usages where the object is used, one line each
     */
    public static function objectInUse(string $objectName, array $usages): self
    {
        return new self(new Message('metadata.in_use.archive_object', 'Object "%object%" cannot be archived, it is still used: %usages%. Remove these uses first.', ['object' => $objectName, 'usages' => new MessageList($usages)]));
    }

    /**
     * @param list<string|Message> $usages where the object is used, one line each
     */
    public static function objectInUseForPurge(string $objectName, array $usages): self
    {
        return new self(new Message('metadata.in_use.purge_object', 'Object "%object%" cannot be purged, it is still used: %usages%. Remove these uses first.', ['object' => $objectName, 'usages' => new MessageList($usages)]));
    }

    /**
     * @param list<string|Message> $usages where the field is used, one line each
     */
    public static function fieldInUse(string $objectName, string $fieldName, array $usages): self
    {
        return new self(new Message('metadata.in_use.archive_field', 'Field "%object%.%field%" cannot be archived, it is still used: %usages%. Remove these uses first.', ['object' => $objectName, 'field' => $fieldName, 'usages' => new MessageList($usages)]));
    }

    /**
     * @param list<string>         $fields
     * @param list<string|Message> $usages where the set is used, one line each
     */
    public static function uniqueTogetherInUse(string $objectName, array $fields, array $usages): self
    {
        return new self(new Message('metadata.in_use.remove_unique_together', 'The unique set (%fields%) of "%object%" cannot be removed, it is still used: %usages%. Remove these uses first.', ['fields' => implode(', ', $fields), 'object' => $objectName, 'usages' => new MessageList($usages)]));
    }

    /**
     * @param list<Message> $problems
     */
    public static function cannotRestoreObject(string $objectName, array $problems): self
    {
        return new self(new Message('metadata.cannot_restore.object', 'Object "%object%" cannot be restored: %problems%.', ['object' => $objectName, 'problems' => new MessageList($problems)]));
    }

    /**
     * @param list<Message> $problems
     */
    public static function cannotRestoreField(string $objectName, string $fieldName, array $problems): self
    {
        return new self(new Message('metadata.cannot_restore.field', 'Field "%object%.%field%" cannot be restored: %problems%.', ['object' => $objectName, 'field' => $fieldName, 'problems' => new MessageList($problems)]));
    }

    public static function duplicateObject(string $name): self
    {
        return new self(new Message('metadata.duplicate_object', 'An object named "%name%" already exists.', ['name' => $name]));
    }

    /**
     * @param list<string> $available
     */
    public static function unknownFieldType(string $type, array $available): self
    {
        return new self(new Message('metadata.unknown_field_type', 'Unknown field type "%type%". Available types: %available%.', ['type' => $type, 'available' => implode(', ', $available)]));
    }

    public static function unknownSearchField(string $objectName, string $fieldName): self
    {
        return new self(new Message('metadata.unknown_search_field', 'Object "%object%" has no field "%field%" to search.', ['object' => $objectName, 'field' => $fieldName]));
    }

    /**
     * @param list<string> $available
     */
    public static function unknownSearchHandler(string $handler, array $available): self
    {
        return new self(new Message('metadata.unknown_search_handler', 'Unknown search handler "%handler%". Available handlers: %available%.', ['handler' => $handler, 'available' => implode(', ', $available)]));
    }

    public static function unknownLabelField(string $objectName, string $fieldName): self
    {
        return new self(new Message('metadata.unknown_label_field', 'Object "%object%" has no field "%field%" to use as label.', ['object' => $objectName, 'field' => $fieldName]));
    }

    public static function unknownRelationTarget(string $target): self
    {
        return new self(new Message('metadata.unknown_relation_target', 'The related object "%target%" does not exist.', ['target' => $target]));
    }

    public static function inverseNameTaken(string $targetName, string $inverseName): self
    {
        return new self(new Message('metadata.inverse_name_taken', 'Object "%object%" already has a field or an inverse relation named "%name%".', ['object' => $targetName, 'name' => $inverseName]));
    }

    public static function unknownField(string $objectName, string $fieldName): self
    {
        return new self(new Message('metadata.unknown_field', 'Object "%object%" has no field "%field%".', ['object' => $objectName, 'field' => $fieldName]));
    }

    public static function notUniqueType(string $fieldName, string $type): self
    {
        return new self(new Message('metadata.not_unique_type', 'Field "%field%" is of type "%type%", which cannot be unique.', ['field' => $fieldName, 'type' => $type]));
    }

    /**
     * @param list<string> $fields
     */
    public static function invalidUniqueTogether(string $objectName, array $fields, Message $reason): self
    {
        return new self(new Message('metadata.invalid_unique_together', 'Fields (%fields%) of "%object%" cannot be unique together: %reason%.', ['fields' => implode(', ', $fields), 'object' => $objectName, 'reason' => $reason]));
    }

    /**
     * @param list<string> $fields
     */
    public static function unknownUniqueTogether(string $objectName, array $fields): self
    {
        return new self(new Message('metadata.unknown_unique_together', 'Fields (%fields%) of "%object%" are not unique together.', ['fields' => implode(', ', $fields), 'object' => $objectName]));
    }

    public static function invalidJunction(string $fieldName, Message $reason): self
    {
        return new self(new Message('metadata.invalid_junction', 'Field "%field%" cannot go through a junction object: %reason%.', ['field' => $fieldName, 'reason' => $reason]));
    }

    public static function writeOnlyField(string $fieldName, FieldUse $use): self
    {
        $parameters = ['field' => $fieldName];

        return new self(match ($use) {
            FieldUse::Unique => new Message('metadata.write_only_field.unique', 'Field "%field%" is write-only (its value is never read back): it cannot be unique.', $parameters),
            FieldUse::Searched => new Message('metadata.write_only_field.searched', 'Field "%field%" is write-only (its value is never read back): it cannot be searched.', $parameters),
            FieldUse::Label => new Message('metadata.write_only_field.label', 'Field "%field%" is write-only (its value is never read back): it cannot be the label of the records.', $parameters),
        });
    }

    public static function manyToManyField(string $fieldName, FieldUse $use): self
    {
        $parameters = ['field' => $fieldName];

        return new self(match ($use) {
            FieldUse::Unique => new Message('metadata.many_to_many_field.unique', 'Field "%field%" links several records (many-to-many): it cannot be unique.', $parameters),
            FieldUse::Searched => new Message('metadata.many_to_many_field.searched', 'Field "%field%" links several records (many-to-many): it cannot be searched.', $parameters),
            FieldUse::Label => new Message('metadata.many_to_many_field.label', 'Field "%field%" links several records (many-to-many): it cannot be the label of the records.', $parameters),
        });
    }

    /**
     * A field whose value is no text of its own (a file: an id) cannot name, find or tell
     * records apart.
     */
    public static function noTextValue(string $fieldName, string $type, FieldUse $use): self
    {
        $parameters = ['field' => $fieldName, 'type' => $type];

        return new self(match ($use) {
            FieldUse::Unique => new Message('metadata.no_text_value.unique', 'Field "%field%" is a %type% field, with no text of its own: it cannot be unique.', $parameters),
            FieldUse::Searched => new Message('metadata.no_text_value.searched', 'Field "%field%" is a %type% field, with no text of its own: it cannot be searched.', $parameters),
            FieldUse::Label => new Message('metadata.no_text_value.label', 'Field "%field%" is a %type% field, with no text of its own: it cannot be the label of the records.', $parameters),
        });
    }

    public static function fixedSetting(string $fieldName, string $setting): self
    {
        return new self(new Message('metadata.fixed_setting', 'Setting "%setting%" of field "%field%" cannot change once the field exists: it shapes the column.', ['setting' => $setting, 'field' => $fieldName]));
    }

    public static function optionStillUsed(string $fieldName, string $value, Message $reason): self
    {
        return new self(new Message('metadata.option_still_used', 'Option "%value%" of field "%field%" cannot be removed: %reason%.', ['value' => $value, 'field' => $fieldName, 'reason' => $reason]));
    }

    public static function invalidDependency(string $fieldName, Message $reason): self
    {
        return new self(new Message('metadata.invalid_dependency', 'Field "%field%" cannot depend on that: %reason%.', ['field' => $fieldName, 'reason' => $reason]));
    }

    public static function invalidCondition(string $fieldName, Message $reason): self
    {
        return new self(new Message('metadata.invalid_condition', 'Field "%field%" cannot get this condition: %reason%.', ['field' => $fieldName, 'reason' => $reason]));
    }

    public static function unknownView(string $objectName, ViewKind $kind, string $name): self
    {
        $parameters = ['object' => $objectName, 'view' => $name];

        return new self(match ($kind) {
            ViewKind::Grid => new Message('metadata.unknown_view.grid', 'Object "%object%" has no grid view "%view%".', $parameters),
            ViewKind::Form => new Message('metadata.unknown_view.form', 'Object "%object%" has no form view "%view%".', $parameters),
        });
    }

    public static function unknownViewField(string $objectName, string $viewName, string $fieldName): self
    {
        return new self(new Message('metadata.unknown_view_field', 'View "%view%": object "%object%" has no field "%field%".', ['view' => $viewName, 'object' => $objectName, 'field' => $fieldName]));
    }

    public static function duplicateViewToken(string $viewName, string $fieldName): self
    {
        return new self(new Message('metadata.duplicate_view_token', 'View "%view%": the field "%field%" is placed more than once.', ['view' => $viewName, 'field' => $fieldName]));
    }

    public static function viewTokenInsideTag(string $viewName): self
    {
        return new self(new Message('metadata.view_token_inside_tag', 'View "%view%": {field} tokens go in the text, not inside a tag or an attribute.', ['view' => $viewName]));
    }
}
