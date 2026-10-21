<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;

/**
 * How a record is named where it is referenced: relation cells, lookups, links.
 */
final class RecordLabels
{
    public function __construct(
        private readonly FieldTypeRegistryInterface $fieldTypes,
    ) {
    }

    /**
     * The configured label field, or the first text field, or null (records are then
     * named by a short form of their id).
     */
    public function fieldFor(ObjectDefinitionDto $object): ?FieldDefinitionDto
    {
        if (null !== $object->labelField && $object->hasField($object->labelField)) {
            return $object->field($object->labelField);
        }
        foreach ($object->fields as $field) {
            $kind = $this->fieldTypes->get($field->type)->widget($field->settings)->kind;
            if (\in_array($kind, [WidgetKind::Text, WidgetKind::Email], true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The label of a record whose label field is empty or missing.
     */
    public static function fallback(string $id): string
    {
        return '#'.substr($id, 0, 8);
    }
}
