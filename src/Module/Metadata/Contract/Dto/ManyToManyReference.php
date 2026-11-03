<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A many-to-many field seen from its target: "object.field links to me".
 *
 * Kept apart from RelationReference: the delete rules differ (no cascade, no set null), and
 * the links are read from a table of their own, not from a column of the object.
 */
final readonly class ManyToManyReference
{
    public function __construct(
        public ObjectDefinitionDto $object,
        public FieldDefinitionDto $field,
        public ManyToManySettings $settings,
    ) {
    }

    /**
     * The name of the inverse side, shown on the target.
     */
    public function inverseLabel(): string
    {
        return $this->settings->inverseLabel ?? \sprintf('%s (%s)', $this->object->labelPlural, $this->field->label);
    }
}
